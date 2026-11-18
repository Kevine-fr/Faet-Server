<?php

namespace App\Services;

use App\Models\Transaction;

class TransactionService
{
    //
    /**
     * Récupérer les transactions paginées du plus récent au plus ancien.
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPaginatedTransactions(int $perPage = 20, array $filters = [])
    {
        return Transaction::with(['order', 'vendor'])
            ->status($filters['status'] ?? null)
            ->type($filters['type'] ?? null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->latest()
            ->paginate($perPage);
    }
}