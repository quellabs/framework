<?php

namespace Quellabs\Recommender;

/** Opaque random 16-byte impression token exposed as lowercase hex. */
readonly class ImpressionId {
    /** @param string $hex 32 lowercase hexadecimal characters. */
    public function __construct(public string $hex) {
        if (preg_match('/^[0-9a-f]{32}$/D', $hex) !== 1) {
            throw new \InvalidArgumentException('Invalid impression ID.');
        }
    }

    /** @return self Fresh random impression token. */
    public static function generate(): self { return new self(bin2hex(random_bytes(16))); }

    /** @return string Raw database key. */
    public function binary(): string {
        $binary = hex2bin($this->hex);
        if ($binary === false) {
            throw new \LogicException('Validated impression ID could not be decoded.');
        }
        return $binary;
    }

    /** @return string Display token. */
    public function __toString(): string { return $this->hex; }
}
