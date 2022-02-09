<?php
namespace kodeops\OpenSeaWrapper\Helpers;

use Symfony\Component\Console\Output\ConsoleOutput as SConsoleOutput;

class ConsoleOutput extends SConsoleOutput
{
    public function info($message)
    {
        return $this->writeln("<info>{$message}</info>");
    }

    public function error($message)
    {
        return $this->writeln("<error>{$message}</error>");
    }

    public function comment($message)
    {
        return $this->writeln("<comment>{$message}</comment>");
    }

    public function warn($message)
    {
        return $this->writeln("<warn>{$message}</warn>");
    }

    public function debug($message)
    {
        if (! env('APP_DEBUG')) {
            return;
        }
        
        return $this->writeln("<comment>{$message}</comment>");
    }
}
