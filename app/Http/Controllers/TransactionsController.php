<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TransactionService;
use App\Http\Resources\TransactionResource;
use App\Http\Requests\TransactionFilterRequest;


class TransactionsController extends Controller
{

    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(TransactionFilterRequest $request)
    {
        $perPage = $request->get('per_page', 20);

        $transactions = $this->transactionService->getPaginatedTransactions(
            $perPage,
            $request->validated()
        );

        return TransactionResource::collection($transactions);
    }
}
