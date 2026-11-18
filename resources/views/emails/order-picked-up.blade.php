<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de retrait - Commande #{{ $order_number }}</title>
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
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: #000;
            padding: 40px 30px;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            position: relative;
        }
        
        .header p {
            margin: 10px 0 0;
            font-size: 16px;
            opacity: 0.9;
            position: relative;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 18px;
            color: #000;
            margin-bottom: 25px;
            font-weight: 600;
        }
        
        .message {
            font-size: 16px;
            color: #000;
            margin-bottom: 30px;
            line-height: 1.7;
        }
        
        /* ==== Section colorée (Informations de retrait) ==== */
        .pickup-info {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            position: relative;
        }
        
        .pickup-info::before {
            content: '✓';
            position: absolute;
            top: -15px;
            left: 25px;
            background: #10b981;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .pickup-info h3 {
            margin: 0 0 15px;
            color: #059669;
            font-size: 18px;
            font-weight: 700;
        }
        
        .pickup-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }
        
        .pickup-detail {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }
        
        .pickup-detail-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        
        .pickup-detail-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 700;
        }
        /* ================================================== */
        
        .order-summary {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            border-left: 4px solid #000;
        }
        
        .order-summary h4 {
            margin: 0 0 15px;
            color: #000;
            font-size: 16px;
            font-weight: 600;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            color: #000;
        }
        
        .summary-label {
            color: #444;
        }
        
        .summary-value {
            color: #000;
            font-weight: 600;
        }
        
        .total-row {
            border-top: 2px solid #ddd;
            padding-top: 8px;
            margin-top: 15px;
            font-weight: 700;
            font-size: 16px;
        }
        
        .footer-message {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
            border: 1px solid #ccc;
        }
        
        .footer-message p {
            margin: 0;
            color: #000;
            font-size: 15px;
            font-weight: 500;
        }
        
        .shop-info {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            border-top: 2px solid #e5e7eb;
        }
        
        .shop-name {
            font-size: 20px;
            font-weight: 700;
            color: #000;
            margin: 0 0 5px;
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
            font-size: 14px;
        }
        
        .footer p {
            margin: 0 0 10px;
        }
        
        @media (max-width: 600px) {
            .email-container {
                margin: 10px;
                border-radius: 8px;
            }
            
            .header, .content {
                padding: 25px 20px;
            }
            
            .pickup-details {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>Commande Récupérée</h1>
            <p>Confirmation de retrait réussie</p>
        </div>
        
        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Bonjour {{ $customer_name }},
            </div>
            
            <div class="message">
                Nous confirmons que votre commande a été <strong>récupérée avec succès</strong> ! 
                Merci d'avoir choisi notre boutique pour vos achats.
            </div>
            
            <!-- Pickup Information (section colorée) -->
            <div class="pickup-info">
                <h3>Informations de retrait</h3>
                <p style="margin: 0 0 10px; color: #059669; font-weight: 500;">
                    Votre commande a été retirée le {{ $pickup_date }} à {{ $pickup_time }}.
                </p>
                
                <div class="pickup-details">
                    <div class="pickup-detail">
                        <div class="pickup-detail-label">Numéro de commande</div>
                        <div class="pickup-detail-value">#{{ $order_number }}</div>
                    </div>
                    <div class="pickup-detail">
                        <div class="pickup-detail-label">Date de retrait</div>
                        <div class="pickup-detail-value">{{ $pickup_date }}</div>
                    </div>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary">
                <h4>Récapitulatif de la commande</h4>
                <div class="summary-row">
                    <span class="summary-label">Numéro de commande :</span>
                    <span class="summary-value">#{{ $order_number }}</span>
                </div>
                <div class="summary-row total-row">
                    <span class="summary-label">Montant total :</span>
                    <span class="summary-value">{{ number_format($order_amount, 2, ',', ' ') }} €</span>
                </div>
            </div>
            
            <!-- Footer Message -->
            <div class="footer-message">
                <p>
                    Merci pour votre confiance ! N'hésitez pas à revenir nous voir pour vos prochains achats.
                </p>
            </div>
            
            <!-- Shop Info -->
            <div class="shop-info">
                <p class="shop-name">{{ $name_store }}</p>
                <p class="shop-subtitle">FAET, votre boutique de confiance</p>
            </div>
            
            <p>
                Cordialement,<br>
                <strong>Boutique {{ $name_store }}</strong>
            </p>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>
                Cet email a été envoyé automatiquement, merci de ne pas y répondre.
            </p>
            <p style="margin-top: 15px;">
                © {{ date('Y') }} {{ $name_store }}. Tous droits réservés.
            </p>
        </div>
    </div>
</body>
</html>