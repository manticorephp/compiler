<?php
use Io\Poll\Context;
use Io\Poll\Backend;
$ctx = new Context();  // Auto
echo "auto -> ", $ctx->getBackend()->name, "\n";
$av = Backend::getAvailableBackends();
$names = [];
foreach ($av as $b) { $names[] = $b->name; }
echo "available: ", implode(",", $names), "\n";
echo "epoll available: ", (Backend::Epoll->isAvailable() ? "yes" : "no"), "\n";
echo "kqueue available: ", (Backend::Kqueue->isAvailable() ? "yes" : "no"), "\n";
