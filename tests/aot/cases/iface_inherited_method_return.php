<?php
// An interface-typed receiver whose implementer INHERITS the method is typed by that body, not by an unrelated same-named method
class Signals { public static function instance(): Signals { return new Signals; } }
interface ContractContainer {
    /**
     * @template TInstance of mixed
     * @param  \Closure|string  $abstract
     * @param  TInstance  $instance
     * @return TInstance
     */
    public function instance($abstract, $instance);
}
interface ContractApp extends ContractContainer { public function shouldSkip(); }
class Cont implements ContractContainer {
    protected $instances = [];
    public function instance($abstract, $instance) { $this->instances[$abstract] = $instance; return $instance; }
}
class App extends Cont implements ContractApp { public function shouldSkip() { return false; } }
class Req { public $n = 1; }
class Kernel {
    /** @var ContractApp */
    protected $app;
    public function __construct(ContractApp $app) { $this->app = $app; }
    public function go($request) {
        $this->app->instance('request', $request);
        return 'done';
    }
}
$k = new Kernel(new App);
echo $k->go(new Req), "\n";
echo $k->go(new Req), "\n";
