<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ClientService
{
    //
    /**
     * Récupérer tous les clients paginés
     *
     */

    // public function getClientPaginated()
    // {
    //     return User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
    //     ->orderByRaw('created_at DESC NULLS LAST')
    //     ->paginate(20);
    // }

    public function getClientPaginated(int $perPage = 20, ?string $status = null)
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Récupérer un role par son ID
     *
     * @param int $id
     * @return Role
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function getAdminRoleId(): int
    {
        return Role::where('name', 'client')->firstOrFail()->id;
    }

    /**
     * Créer un nouvel utilisateur Client
     *
     * @param array $data
     * @return User
     * @throws \Exception
     */
    public function createClient(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'phone'      => $data['phone'] ?? null,
                'password'   => Hash::make($data['password']),
                'status'     => 'active',
            ]);

            // Associer le rôle "admin"
            $user->roles()->attach($this->getAdminRoleId());

            return $user;
        });
    }

}