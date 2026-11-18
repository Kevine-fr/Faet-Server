<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annulation de commande</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f2f2f2;
            color: #000;
            line-height: 1.6;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: #000;
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px 30px;
            color: #000;
        }
        
        /* === SECTION CONSERVÉE EN COULEUR === */
        .alert-box {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .alert-icon {
            width: 24px;
            height: 24px;
            background-color: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .alert-content h2 {
            margin: 0 0 10px 0;
            color: #dc2626;
            font-size: 18px;
            font-weight: 600;
        }
        
        .alert-content p {
            margin: 0;
            color: #991b1b;
            font-size: 14px;
        }
        /* =============================== */
        
        .order-details {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 25px;
            margin: 30px 0;
            color: #000;
        }
        
        .order-details h3 {
            margin: 0 0 20px 0;
            color: #000;
            font-size: 18px;
            font-weight: 600;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            font-weight: 600;
            color: #000;
        }
        
        .detail-label {
            color: #444;
            font-size: 14px;
        }
        
        .detail-value {
            color: #000;
            font-weight: 500;
            text-align: right;
        }
        
        .reason-box {
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }
        
        .reason-box h4 {
            margin: 0 0 10px 0;
            color: #000;
            font-size: 16px;
            font-weight: 600;
        }
        
        .reason-text {
            color: #333;
            font-style: italic;
            line-height: 1.5;
            margin: 0;
        }
        
        .shop-info {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
        }
        
        .shop-name {
            font-size: 18px;
            font-weight: 600;
            color: #000;
            margin: 0 0 5px 0;
        }
        
        .shop-subtitle {
            font-size: 14px;
            color: #666;
            margin: 0;
        }
        
        .footer {
            background-color: #000;
            padding: 30px;
            text-align: center;
            color: #f2f2f2;
        }
        
        .footer p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .footer a {
            color: #fff;
            text-decoration: underline;
        }
        
        @media (max-width: 600px) {
            .email-container {
                margin: 0;
                box-shadow: none;
            }
            
            .header, .content, .footer {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .detail-value {
                text-align: left;
                font-weight: 600;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>Commande Annulée</h1>
            <p>Notification d'annulation de commande</p>
        </div>
        
        <!-- Content -->
        <div class="content">
            <p>Bonjour <strong>{{ $customer_name }}</strong>,</p>
            
            <!-- Alert Box (restée colorée) -->
            <div class="alert-box">
                <div class="alert-icon">!</div>
                <div class="alert-content">
                    <h2>Votre commande a été annulée</h2>
                    <p>Nous vous informons que votre commande a été annulée par notre équipe le {{ $cancellation_date }}.</p>
                </div>
            </div>
            
            <!-- Order Details -->
            <div class="order-details">
                <h3>Détails de la commande</h3>
                <div class="detail-row">
                    <span class="detail-label">Numéro de commande</span>
                    <span class="detail-value">#{{ $order_number }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Montant</span>
                    <span class="detail-value">{{ number_format($order_amount, 2, ',', ' ') }} €</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date d'annulation</span>
                    <span class="detail-value">{{ $cancellation_date }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Statut</span>
                    <span class="detail-value" style="color: #000;">Annulée</span>
                </div>
            </div>
            
            <!-- Reason Box -->
            <div class="reason-box">
                <h4>Raison de l'annulation</h4>
                <p class="reason-text">{{ $cancellation_reason }}</p>
            </div>
            
            <!-- Shop Info -->
            <div class="shop-info">
                <p class="shop-name">{{ $name_store }}</p>
                <p class="shop-subtitle">FAET, votre boutique de confiance</p>
            </div>
            
            <p>
                Nous nous excusons pour ce désagrément. Si vous avez des questions concernant cette annulation, 
                n'hésitez pas à nous contacter.
            </p>
            
            <p>
                <strong>Important :</strong> Si vous aviez effectué un paiement, le remboursement sera traité 
                automatiquement dans les prochains jours ouvrables.
            </p>
            
            <p>
                Cordialement,<br>
                <strong>Boutique {{ $name_store }}</strong>
            </p>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>
                Cet email a été envoyé automatiquement. Merci de ne pas y répondre directement.<br>
                Si vous avez des questions, contactez-nous via notre site web.
            </p>
            <p style="margin-top: 15px;">
                © {{ date('Y') }} {{ $name_store }}. Tous droits réservés.
            </p>
        </div>
    </div>
</body>
</html>