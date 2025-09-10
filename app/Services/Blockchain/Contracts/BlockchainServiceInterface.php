<?php

namespace App\Services\Blockchain\Contracts;

interface BlockchainServiceInterface
{
    /**
     * Get the current balance of an address
     */
    public function getBalance(string $address): float;

    /**
     * Get number of confirmations for a transaction
     */
    public function getConfirmations(string $txHash): int;

    /**
     * Find transaction by address and amount
     */
    public function findTransactionByAddress(string $address, float $amount, int $hours = 24): ?string;

    /**
     * Get transaction details
     */
    public function getTransaction(string $txHash): ?array;

    /**
     * Validate address format
     */
    public function isValidAddress(string $address): bool;

    /**
     * Generate a new address (if supported)
     */
    public function generateAddress(): ?array;

    /**
     * Send transaction (if supported)
     */
    public function sendTransaction(string $fromAddress, string $toAddress, float $amount, string $privateKey): ?string;
}