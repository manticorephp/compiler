<?php
class Cmd { function isHidden(): bool { return false; } function isOn(): bool { return true; } function getName(): string { return 'g'; } }
function f(Cmd $command): void {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->appendChild($commandXML = $dom->createElement('command'));
    $commandXML->setAttribute('id', $command->getName());
    $commandXML->setAttribute('hidden', $command->isHidden() ? 1 : 0);
    $commandXML->setAttribute('on', $command->isOn() ? 1 : 0);
    echo $dom->saveXML();
}
f(new Cmd);
