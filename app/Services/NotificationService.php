<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    /**
     * Obtenir les notifications d'un vendeur
     */
    public function getVendorNotifications(int $vendorId, int $perPage = 15, bool $unreadOnly = false): LengthAwarePaginator
    {
        $query = Notification::forVendor($vendorId)
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->unread();
        }

        return $query->paginate($perPage);
    }

    /**
     * Obtenir la dernière notification non lue
     */
    public function getLatestNotification(int $vendorId): ?Notification
    {
        return Notification::forVendor($vendorId)
            ->unread()
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Obtenir le nombre de notifications non lues
     */
    public function getUnreadCount(int $vendorId): int
    {
        return Notification::forVendor($vendorId)
            ->unread()
            ->count();
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(int $notificationId, int $vendorId): bool
    {
        $notification = Notification::forVendor($vendorId)
            ->find($notificationId);

        if (!$notification) {
            return false;
        }

        return $notification->markAsRead();
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(int $vendorId): int
    {
        $notifications = Notification::forVendor($vendorId)
            ->unread()
            ->get();

        $count = 0;
        foreach ($notifications as $notification) {
            if ($notification->markAsRead()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Supprimer une notification
     */
    public function deleteNotification(int $notificationId, int $vendorId): bool
    {
        $notification = Notification::forVendor($vendorId)
            ->find($notificationId);

        if (!$notification) {
            return false;
        }

        return $notification->delete();
    }

    /**
     * Créer une notification pour un vendeur
     */
    public function createVendorNotification(array $data): Notification
    {
        return Notification::create([
            'vendor_id' => $data['vendor_id'],
            'title' => $data['title'],
            'message' => $data['message'],
            'type' => $data['type'],
            'priority' => $data['priority'] ?? 'medium',
            'metadata' => $data['metadata'] ?? null,
            'is_read' => false,
            'is_sent' => false
        ]);
    }

    /**
     * Créer une notification de commande
     */
    public function createOrderNotification(int $vendorId, string $orderId, string $action): Notification
    {
        $type = match($action) {
            'new' => 'order',
            'canceled' => 'order_canceled',
            default => 'order'
        };

        $title = match($action) {
            'new' => 'Nouvelle commande reçue',
            'canceled' => 'Commande annulée',
            default => 'Mise à jour de commande'
        };

        $message = match($action) {
            'new' => "Vous avez reçu une nouvelle commande #{$orderId}",
            'canceled' => "La commande #{$orderId} a été annulée",
            default => "La commande #{$orderId} a été mise à jour"
        };

        return $this->createVendorNotification([
            'vendor_id' => $vendorId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'priority' => $action === 'canceled' ? 'high' : 'medium',
            'metadata' => ['order_id' => $orderId, 'action' => $action]
        ]);
    }

    /**
     * Créer une notification de stock faible
     */
    public function createLowStockNotification(int $vendorId, string $productName, int $currentStock): Notification
    {
        return $this->createVendorNotification([
            'vendor_id' => $vendorId,
            'title' => 'Stock faible détecté',
            'message' => "Le produit '{$productName}' a un stock faible ({$currentStock} unités restantes)",
            'type' => 'low_stock',
            'priority' => 'high',
            'metadata' => [
                'product_name' => $productName,
                'current_stock' => $currentStock
            ]
        ]);
    }

    /**
     * Créer une notification de maintenance
     */
    public function createMaintenanceNotification(int $vendorId, string $message, string $priority = 'medium'): Notification
    {
        return $this->createVendorNotification([
            'vendor_id' => $vendorId,
            'title' => 'Maintenance système',
            'message' => $message,
            'type' => 'maintenance',
            'priority' => $priority
        ]);
    }

    /**
     * Créer une notification de mise à jour
     */
    public function createUpdateNotification(int $vendorId, string $version, string $description): Notification
    {
        return $this->createVendorNotification([
            'vendor_id' => $vendorId,
            'title' => 'Mise à jour disponible',
            'message' => "Nouvelle version {$version} : {$description}",
            'type' => 'update',
            'priority' => 'medium',
            'metadata' => ['version' => $version]
        ]);
    }

    /**
     * Créer un message administrateur
     */
    public function createAdminMessage(int $vendorId, string $title, string $message, int $adminId, string $priority = 'medium'): Notification
    {
        return Notification::create([
            'vendor_id' => $vendorId,
            'admin_id' => $adminId,
            'title' => $title,
            'message' => $message,
            'type' => 'admin_message',
            'priority' => $priority,
            'is_read' => false,
            'is_sent' => false
        ]);
    }

    /**
     * Obtenir les statistiques des notifications
     */
    public function getNotificationStats(int $vendorId): array
    {
        $total = Notification::forVendor($vendorId)->count();
        $unread = Notification::forVendor($vendorId)->unread()->count();
        $read = $total - $unread;

        $byType = Notification::forVendor($vendorId)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byPriority = Notification::forVendor($vendorId)
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $read,
            'by_type' => $byType,
            'by_priority' => $byPriority
        ];
    }
}
