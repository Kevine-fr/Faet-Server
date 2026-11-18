<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\StoreClientRequest;
use App\Services\ClientService;
use App\Http\Resources\UserResource;


class ClientController extends Controller
{
    //

    protected $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreClientRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function storeClient(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name'       => 'required|string|max:255',
                'last_name'        => 'required|string|max:255',
                'phone'            => ['required', 'string', 'regex:/^(?:\+?\d{1,3})?[0-9]{7,15}$/'],
                'profile_picture'  => 'nullable|string|max:255',
                'email'            => 'required|email|unique:users,email',
                'password'         => 'required|string|min:8',
            ]);

            $client = $this->clientService->createClient($validated);

            return response()->json([
                'message' => 'Client créé avec succès',
                'client'  => $client
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du client',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /*
        * Display a listing of the resource.

    */

    public function lisClient(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $status  = $request->get('status'); // active | blocked | inactive

            $clients = $this->clientService->getClientPaginated($perPage, $status);

            return UserResource::collection($clients)
                ->additional([
                    'meta' => [
                        'current_page' => $clients->currentPage(),
                        'last_page'    => $clients->lastPage(),
                        'per_page'     => $clients->perPage(),
                        'total'        => $clients->total(),
                    ]
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer la liste des clients.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }




}