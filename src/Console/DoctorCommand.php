<?php

namespace Misakstvanu\ZssoClient\Console;

use Illuminate\Console\Command;
use Misakstvanu\ZssoClient\ClientCredentials;
use Misakstvanu\ZssoClient\Discovery;
use Misakstvanu\ZssoClient\JsonWebKeySet;

class DoctorCommand extends Command
{
    protected $signature = 'zsso:doctor';

    protected $description = 'Check this app\'s zSSO client configuration';

    /**
     * The configuration this app cannot talk to zSSO without.
     *
     * @var array<int, string>
     */
    protected const REQUIRED = ['server_url', 'client_id', 'client_secret', 'app_slug'];

    /**
     * Every check runs against the server rather than against the cache: a
     * cached document would hide the outage the command is asked about.
     */
    public function handle(Discovery $discovery, JsonWebKeySet $keys, ClientCredentials $credentials): int
    {
        $configured = $this->checkConfiguration();

        $checks = [
            $this->checkDiscovery($discovery, $configured),
            $this->checkKeys($discovery, $keys, $configured),
            $this->checkToken($discovery, $credentials, $configured),
        ];

        return $configured && ! in_array(false, $checks, strict: true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    protected function checkConfiguration(): bool
    {
        $ok = true;

        foreach (self::REQUIRED as $key) {
            $ok = $this->result(filled(config("zsso.{$key}")), "zsso.{$key}", 'is not set') && $ok;
        }

        return $ok;
    }

    /**
     * A server that answers with no document is not fatal — every endpoint
     * then falls back to its conventional path — but it is what the command
     * is here to say out loud.
     */
    protected function checkDiscovery(Discovery $discovery, bool $configured): bool
    {
        if (! $configured) {
            return $this->result(false, 'discovery document', 'not checked, the configuration is incomplete');
        }

        $discovery->forget();

        return $this->result(
            $discovery->document() !== [],
            'discovery document',
            $discovery->issuer().Discovery::PATH.' did not answer with one',
        );
    }

    protected function checkKeys(Discovery $discovery, JsonWebKeySet $keys, bool $configured): bool
    {
        if (! $configured) {
            return $this->result(false, 'signing keys', 'not checked, the configuration is incomplete');
        }

        $keys->forget();

        return $this->result(
            $keys->pems() !== [],
            'signing keys',
            $discovery->url('jwks_uri').' published no usable RSA key',
        );
    }

    protected function checkToken(Discovery $discovery, ClientCredentials $credentials, bool $configured): bool
    {
        if (! $configured) {
            return $this->result(false, 'client-credentials token', 'not checked, the configuration is incomplete');
        }

        $credentials->forget();

        return $this->result(
            $credentials->token() !== null,
            'client-credentials token',
            $discovery->url('token_endpoint').' minted none for zsso.client_id with the scope '.ClientCredentials::SCOPE,
        );
    }

    /**
     * One ✔/✘ line; the reason is only worth printing when the check failed.
     */
    protected function result(bool $ok, string $label, string $reason): bool
    {
        $this->line(sprintf(
            ' %s  %s%s',
            $ok ? '<fg=green>✔</>' : '<fg=red>✘</>',
            $label,
            $ok ? '' : ' <fg=gray>'.$reason.'</>',
        ));

        return $ok;
    }
}
