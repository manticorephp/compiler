<?php
namespace Io\Poll {
    interface Handle {}
    enum Backend { case Auto; case Epoll; }
    enum Event { case Read; case Write; }
    final class Context {
        public function __construct(public Backend $backend = Backend::Auto) {}
        public function getBackend(): Backend { return $this->backend; }
    }
}
namespace {
    $c = new \Io\Poll\Context();
    echo $c->getBackend()->name, "\n";
    echo \Io\Poll\Backend::Epoll->name, "\n";
    echo \Io\Poll\Event::Read->name, "\n";
    var_dump($c instanceof \Io\Poll\Context);
}
