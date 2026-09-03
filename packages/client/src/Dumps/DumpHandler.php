<?php

namespace Nomeus\Client\Dumps;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\ContextProvider\CliContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\ContextProviderInterface;
use Symfony\Component\VarDumper\Dumper\ContextProvider\RequestContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\SourceContextProvider;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\Dumper\ServerDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * VarDumper's default server handler, plus a `nomeus` context carrying the request id — so a
 * dump() and the queries recorded in the same request group together on the Debug page.
 * Falls back to normal output when the server is not listening (ServerDumper does that itself).
 */
final class DumpHandler
{
    public static function register(string $host): void
    {
        $cloner = new VarCloner;
        $providers = ['nomeus' => new class implements ContextProviderInterface
        {
            public function getContext(): ?array
            {
                return ['request_id' => Sender::requestId()];
            }
        }];
        if (PHP_SAPI === 'cli') {
            $providers['cli'] = new CliContextProvider;
        } else {
            $stack = new RequestStack;
            $stack->push(Request::createFromGlobals());
            $providers['request'] = new RequestContextProvider($stack);
        }
        $providers['source'] = new SourceContextProvider;

        $fallback = PHP_SAPI === 'cli' ? new CliDumper : new HtmlDumper;
        $dumper = new ServerDumper('tcp://'.$host, $fallback, $providers);

        VarDumper::setHandler(function ($var, ?string $label = null) use ($cloner, $dumper) {
            $data = $cloner->cloneVar($var);
            if ($label !== null) {
                $data = $data->withContext(['label' => $label]);
            }
            $dumper->dump($data);
        });
    }
}
