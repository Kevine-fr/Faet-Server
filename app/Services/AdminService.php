<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Mail;
use App\Mail\AdminCreatedMail;

class AdminService
{
    //

    public function getAllAdmins(int $perPage = 20, ?string $status = null)
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /*
    * Créer un nouvel administrateur
    */
    public function createAdmin(array $data)
    {
        return DB::transaction(function () use ($data) {
            $plainPassword = $data['password']; // garder une copie avant de le hasher

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone'=> $data['phone'],
                'password' => Hash::make($plainPassword),
                'status'   => 'blocked',
            ]);

            $user->roles()->attach($this->getAdminRoleId());

            // Envoi du mail avec les identifiants
            Mail::to($user->email)->send(new AdminCreatedMail([
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'password' => $plainPassword,
            ]));

            return $user;
        });
    }
    /**
     * Récupérer l'ID du rôle "admin"
     *
     * @return int
     */

    protected function getAdminRoleId(): int
    {
        return Role::where('name', 'admin')->firstOrFail()->id;
    }

    /**
     * Mettre à jour un utilisateur
     *
     * @param User $user
     * @param array $data
     * @return User
     */
    public function updateUser(User $user, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return $user->fresh(); // rafraîchir les données en BDD
    }

    /**
     *
     * Bloquer un utilisateur
     */
    public function blockUser(int $userId): User
    {
        return DB::transaction(function () use ($userId) {
            $user = User::findOrFail($userId);

            if ($user->status === 'blocked') {
                throw new \Exception("L'utilisateur est déjà bloqué.");
            }

            $user->update(['status' => 'blocked']);

            return $user;
        });
    }

     /**
      *
     * Réactiver un utilisateur bloqué
     */
    public function activateUser(int $userId): User
    {
        return DB::transaction(function () use ($userId) {
            $user = User::findOrFail($userId);

            if ($user->status === 'active') {
                throw new \Exception("L'utilisateur est déjà actif.");
            }

            $user->update(['status' => 'active']);

            return $user;
        });
        }
    /**
     * Rechercher un utilisateur par ID (sans filtrer par rôle)
     */
    public function findById(int $id)
    {
        return User::findOrFail($id);
    }


}
