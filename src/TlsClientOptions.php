<?php
declare(strict_types=1);

final class TlsClientOptions
{
    public function __construct(
        public readonly bool $verifyPeer = true,
        public readonly int $timeoutSec = 2,
        public readonly string $alpn = 'h2,http/1.1',
        public readonly ?string $peerName = null,
    ) {
    }

    public function withVerifyPeer(bool $verifyPeer): self
    {
        return new self($verifyPeer, $this->timeoutSec, $this->alpn, $this->peerName);
    }

    public function withTimeoutSec(int $timeoutSec): self
    {
        return new self($this->verifyPeer, $timeoutSec, $this->alpn, $this->peerName);
    }

    public function withAlpn(string $alpn): self
    {
        return new self($this->verifyPeer, $this->timeoutSec, $alpn, $this->peerName);
    }

    public function withPeerName(?string $peerName): self
    {
        return new self($this->verifyPeer, $this->timeoutSec, $this->alpn, $peerName);
    }
}
