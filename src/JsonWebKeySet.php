<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The zSSO server's signing keys (the `jwks_uri` of contract C-1), so an
 * incoming access token is verified without asking the server about it.
 *
 * The set is cached for `zsso.cache_ttl` seconds. A token naming a `kid` the
 * cached set does not hold is what a rotated signing key looks like, so the
 * set is then fetched again — once.
 */
class JsonWebKeySet
{
    /**
     * The parsed set: one entry per usable RSA key.
     *
     * @var array<int, array{kid: string|null, pem: string}>|null
     */
    protected ?array $keys = null;

    public function __construct(protected Discovery $discovery) {}

    /**
     * The PEM public keys a token may have been signed with. A token without
     * a `kid` header — Passport issues those — is checked against every key
     * in the set.
     *
     * @return array<int, string>
     */
    public function pems(?string $kid = null): array
    {
        $pems = $this->select($this->keys(), $kid);

        return $pems !== [] ? $pems : $this->select($this->keys(fresh: true), $kid);
    }

    /**
     * Drop the memoised and the cached set.
     */
    public function forget(): void
    {
        $this->keys = null;

        Cache::forget($this->cacheKey());
    }

    /**
     * @return array<int, array{kid: string|null, pem: string}>
     */
    protected function keys(bool $fresh = false): array
    {
        if ($fresh) {
            $this->forget();
        }

        if ($this->keys !== null) {
            return $this->keys;
        }

        $document = Cache::get($this->cacheKey());

        if (! is_array($document)) {
            $document = $this->fetch();

            if ($document !== []) {
                Cache::put($this->cacheKey(), $document, $this->ttl());
            }
        }

        return $this->keys = $this->parse($document);
    }

    /**
     * @param  array<int, array{kid: string|null, pem: string}>  $keys
     * @return array<int, string>
     */
    protected function select(array $keys, ?string $kid): array
    {
        $matching = $kid === null
            ? $keys
            : array_filter($keys, static fn (array $key): bool => $key['kid'] === $kid);

        return array_values(array_map(static fn (array $key): string => $key['pem'], $matching));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(): array
    {
        try {
            $response = Http::acceptJson()->timeout(5)->get($this->discovery->url('jwks_uri'));
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $document = $response->json();

        return is_array($document) ? $document : [];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array{kid: string|null, pem: string}>
     */
    protected function parse(array $document): array
    {
        $keys = [];

        foreach ($document['keys'] ?? [] as $key) {
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA') {
                continue;
            }

            if (! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
                continue;
            }

            $keys[] = [
                'kid' => is_string($key['kid'] ?? null) ? $key['kid'] : null,
                'pem' => static::pem($key['n'], $key['e']),
            ];
        }

        return $keys;
    }

    /**
     * An RSA JWK as a PEM public key: PHP can verify with a PEM only, and
     * neither openssl nor lcobucci/jwt reads a JWK. The bytes below are a
     * SubjectPublicKeyInfo (RFC 5280) wrapping an RSAPublicKey (RFC 8017).
     */
    protected static function pem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = static::sequence(
            static::integer(static::base64url($modulus)).static::integer(static::base64url($exponent))
        );

        // OID 1.2.840.113549.1.1.1 (rsaEncryption) followed by an ASN.1 NULL.
        $algorithm = static::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");

        $der = static::sequence($algorithm.static::bitString($rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    protected static function base64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), strict: false);
    }

    protected static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".static::length(strlen($bytes)).$bytes;
    }

    protected static function sequence(string $contents): string
    {
        return "\x30".static::length(strlen($contents)).$contents;
    }

    protected static function bitString(string $contents): string
    {
        return "\x03".static::length(strlen($contents) + 1)."\x00".$contents;
    }

    protected static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    protected function cacheKey(): string
    {
        return 'zsso.jwks.'.sha1($this->discovery->issuer());
    }

    protected function ttl(): int
    {
        return (int) config('zsso.cache_ttl', 3600);
    }
}
