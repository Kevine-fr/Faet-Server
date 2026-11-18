<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Obtenir les notifications d'un vendeur
     */
    public function getVendorNotifications(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || $user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $perPage = $request->get('per_page', 15);
            $unreadOnly = $request->get('unread_only', false);

            $notifications = $this->notificationService->getVendorNotifications(
                $vendor->id,
                $perPage,
                $unreadOnly
            );

            return response()->json([
                'success' => true,
                'data' => $notifications,
                'unread_count' => $this->notificationService->getUnreadCount($vendor->id)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir la dernière notification non lue
     */
    public function getLatestNotification(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || $user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $notification = $this->notificationService->getLatestNotification($vendor->id);

            return response()->json([
                'success' => true,
                'data' => $notification,
                'unread_count' => $this->notificationService->getUnreadCount($vendor->id)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_id' => 'required|integer|exists:notifications,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            if (!$user || $user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $success = $this->notificationService->markAsRead(
                $request->notification_id,
                $vendor->id
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Notification marquée comme lue',
                    'unread_count' => $this->notificationService->getUnreadCount($vendor->id)
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Impossible de marquer la notification comme lue'
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || $user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $count = $this->notificationService->markAllAsRead($vendor->id);

            return response()->json([
                'success' => true,
                'message' => "{$count} notifications marquées comme lues",
                'unread_count' => 0
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une notification
     */
    public function deleteNotification(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_id' => 'required|integer|exists:notifications,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            if (!$user || $user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $success = $this->notificationService->deleteNotification(
                $request->notification_id,
                $vendor->id
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Notification supprimée',
                    'unread_count' => $this->notificationService->getUnreadCount($vendor->id)
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer la notification'
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
