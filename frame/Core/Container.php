<?php

namespace Mini\Core;
use Mini\Contracts\ApplicationAware;
use Mini\Application;

class Container{

    private $aliases = [];  

    private $bindings = [];

    private $with = [];

    private $buildStack = [];

    private $instances = [];

    private $abstractAliases;

    protected static $instance;



    private function __construct() 
    {
    }

    public static function getInstance() 
    {
        if(is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    public static function setInstance(Container $instance) {
        return static::$instance = $instance;
    }

    public function bind($abstract, $concrete = null, $shared = false){
        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        //如果绑定方法不是闭包，先封个闭包
        if (! $concrete instanceof \Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }


    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->resolve(
                $concrete, $parameters
            );
        };
    }

    /**
     * Drop all of the stale instances and aliases.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->aliases[$abstract]);
    }


    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     *
     */
    public function make($abstract, $parameters = []){
        return $this->resolve($abstract, $parameters);
    }


    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     *
     */
    protected function resolve($abstract, $parameters){

        //获取注入类型真正的名字
        $abstract = $this->getAlias($abstract);

        //如果实例存在，直接返回实例
        if(isset($this->instances[$abstract])) {
             return $this->instances[$abstract];
        }

        //ParameterOverride
        $this->with[] = $parameters;

        //获取实现方法
        $concrete = $this->getConcrete($abstract);

        //实例化,这里主要针对递归绑定的情况，A绑定了B，B依然是个绑定
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // 如果是单例模式，放入实例列表
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        array_pop($this->with);

        return $object;

    }

    /**
     * Register a shared binding in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    protected function getConcrete($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param  string  $abstract
     * @return string
     */
    public function getAlias($abstract)
    {
        if (! isset($this->aliases[$abstract])) {
            return $abstract;
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Determine if a given string is an alias.
     *
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    protected function isBuildable($concrete, $abstract)
    {
        return $concrete == $abstract || $concrete instanceof \Closure;
    }


    public function build($concrete) {        

        //如果是实现是一个闭包函数，返回闭包函数
        if ($concrete instanceof \Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        //否则是一个类，通过反射来实例化
        $reflector = new \ReflectionClass($concrete);
        if (! $reflector->isInstantiable()) {
            return $this->notInstantiable($concrete);
        }

        //实例化栈，暂存待解析实例
        $this->buildStack[] = $concrete;
        $constructor = $reflector->getConstructor();
        
        //没有构造函数，直接实例化
        if (is_null($constructor)) {
            array_pop($this->buildStack);
        
            $instance = new $concrete;
            
            $this->injectApplication($instance);

            return $instance;
        }

        //否则解析依赖
        $dependencies = $constructor->getParameters();

        $instances = $this->resolveDependencies($dependencies);

        array_pop($this->buildStack);

        //用依赖构建实例
        $instance = $reflector->newInstanceArgs($instances);

        $this->injectApplication($instance);

        return $instance;
    }

    protected function injectApplication($instance) 
    {
        if($instance instanceof ApplicationAware) {
            $instance->setApplication($this->make('app'));
        }
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     *
     * @param  string  $concrete
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function notInstantiable($concrete)
    {
        if (! empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new \Exception($message);
    }


    protected function resolveDependencies(array $dependencies) {

        $results = [];

        foreach ($dependencies as $dependency) {
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);
                continue;
            }

            $results[] = is_null($dependency->getClass()) ? $this->resolvePrimitive($dependency) : $this->resolveClass($dependency);
        }
        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param  \ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  \ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Exception
     */
    protected function resolvePrimitive(\ReflectionParameter $parameter)
    {
        //尝试获取默认参数
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        //没有就报错
        $this->unresolvablePrimitive($parameter);
    }

    
    /**
     * Throw an exception for an unresolvable primitive.
     *
     * @param  \ReflectionParameter  $parameter
     * @return void
     *
     * @throws \Exception
     */
    protected function unresolvablePrimitive(\ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new \Exception($message);
    }

    protected function resolveClass(\ReflectionParameter $parameter)
    {
        try{
            return $this->make($parameter->getClass()->name);
        }catch(\Exception $e){
            if($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }
            throw $e;
        }
    }


    /**
     * Register an existing instance as shared in the container.
     *
     * @param  string  $abstract
     * @param  mixed   $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $this->removeAbstractAlias($abstract);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        $this->instances[$abstract] = $instance;

        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }


    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched)
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) ||
               isset($this->instances[$abstract]) ||
               $this->isAlias($abstract);
    }

    /**
     *  {@inheritdoc}
     */
    public function has($id)
    {
        return $this->bound($id);
    }

    /**
     * Determine if a given type is shared.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        return isset($this->instances[$abstract]) ||
               (isset($this->bindings[$abstract]['shared']) &&
               $this->bindings[$abstract]['shared'] === true);
    }

}