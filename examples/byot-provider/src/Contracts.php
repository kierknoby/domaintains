<?php
declare(strict_types=1);

namespace Domaintains\Byot;

interface BillingAdapter
{
    public function pbx(string $account, string $pbx): array;
    public function voice(string $account, string $voice): array;
}

interface ServiceRegistry
{
    public function link(string $account, string $pbx, string $service, array $voices, int $now, bool $rotate = false, ?callable $beforeIssue = null): array;
    public function status(string $service): array;
    public function setStatus(string $service, string $status): void;
    public function authorise(string $service, string $tokenHash, int $now): array;
    public function verifySnapshot(string $service, array $record, int $now): void;
}

interface NumberProvider
{
    public function ensureAllocated(string $number, string $account, string $service): array;
    public function ensureActive(string $number, string $service): array;
    public function ensureDestination(string $number, string $service, array $destination): array;
    public function verifyDestination(string $number, string $service, array $destination): array;
    public function removeOwnedDestinations(string $service, array $numbers): array;
}

interface PbxEndpointResolver
{
    public function resolveAndAuthoriseDestination(string $service, string $publicKey, array $record): array;
}

interface DnsAdapter
{
    public function ensureRecord(string $service, array $destination): array;
    public function verifyRecord(string $service, array $destination): array;
}

interface SipEdgeProvisioner
{
    public function ensurePbxTrunk(string $service, array $plan): array;
    public function ensureInboundNumbers(string $service, array $plan): array;
    public function ensureOutboundRoutes(string $service, array $plan): array;
    public function apply(string $service): array;
    public function verify(string $service, array $plan): array;
    public function removeOwned(string $service, array $identities): array;
}

interface EmergencyAuthority
{
    public function contexts(CustomerIdentity $customer): array;
    public function withLease(CustomerIdentity $customer, string $service, string $pbx, ?string $numberId, callable $operation): mixed;
}

interface EmergencyBillingAdapter extends BillingAdapter
{
    public function withEmergencyAssignments(string $account, string $service, string $pbx, array $voices, callable $operation): mixed;
    public function emergencyAssignment(string $account, string $service, string $pbx, string $number): string;
}

interface EmergencyRegistrationAdapter
{
    public function minimumInterval(): int;
    public function rateLimits(): array;
    public function supportsTypeChange(): bool;
    public function retrieve(EmergencyLease $lease): array;
    public function submit(EmergencyLease $lease, array $registration, string $revision, string $idempotencyKey): array;
    public function status(EmergencyLease $lease): array;
}

interface CustomerAuthenticator
{
    public function current(): ?CustomerIdentity;
}