<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande prête - {{ $name_store }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #ffffff;
            color: #111111;
            line-height: 1.6;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e5e5;
        }

        .header {
            background: #000;
            padding: 40px 30px;
            text-align: center;
            color: #fff;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }

        .header p {
            margin: 8px 0 0;
            font-size: 16px;
            opacity: 0.9;
        }

        .content {
            padding: 40px 30px;
        }

        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #000;
        }

        /* Boîte succès (seule colorée) */
        .success-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            position: relative;
        }

        .success-box::before {
            content: '✓';
            position: absolute;
            top: -15px;
            left: 25px;
            background: #10b981;
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        .success-content h2 {
            margin: 0 0 8px;
            color: #059669;
            font-size: 18px;
            font-weight: 700;
        }

        .success-content p {
            margin: 0;
            color: #065f46;
            font-size: 15px;
            line-height: 1.6;
        }

        /* Résumé commande (noir & blanc) */
        .order-summary {
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .order-summary h3 {
            margin: 0 0 15px;
            color: #000;
            font-size: 17px;
            font-weight: 600;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-label {
            color: #555;
        }

        .summary-value {
            font-weight: 600;
            color: #000;
        }

        .summary-value.status {
            color: #10b981;
        }

        /* Boîte adresse de retrait (noire) */
        .pickup-box {
            background: #000;
            color: #fff;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }

        .pickup-box h4 {
            margin: 0 0 15px;
            font-size: 20px;
            font-weight: 700;
        }

        .pickup-address {
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            line-height: 1.5;
            font-size: 15px;
        }

        .pickup-instructions {
            opacity: 0.85;
            font-size: 14px;
        }

        /* Informations importantes (noir & blanc) */
        .important-note {
            background: #000;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 25px;
            margin: 35px 0;
            color: #fff;
        }

        .important-note h4 {
            margin: 0 0 12px;
            color: #fff;
            font-size: 17px;
            font-weight: 700;
        }

        .important-note p {
            margin: 0;
            color: #f5f5f5;
            font-size: 14px;
            line-height: 1.6;
        }

        .shop-info {
            text-align: center;
            margin: 25px 0;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .shop-name {
            font-size: 20px;
            font-weight: 700;
            color: #000;
            margin: 0 0 5px;
        }

        .shop-subtitle {
            font-size: 14px;
            color: #555;
        }

        .footer {
            background-color: #000;
            padding: 25px;
            text-align: center;
            color: #999;
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

            .pickup-box, .important-note {
                padding: 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .pickup-address {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>Commande prête</h1>
            <p>Votre commande vous attend en boutique</p>
        </div>

        <!-- Content -->
        <div class="content">
            <p class="greeting">Bonjour {{ $customer_name }},</p>

            <!-- Success Box -->
            <div class="success-box">
                <div class="success-content">
                    <h2>Bonne nouvelle !</h2>
                    <p>Votre commande est maintenant prête et vous attend en boutique. Vous pouvez venir la récupérer dès maintenant.</p>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="order-summary">
                <h3>Détails de la commande</h3>
                <div class="summary-row">
                    <span class="summary-label">Numéro de commande :</span>
                    <span class="summary-value">#{{ $order_number }}</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Montant :</span>
                    <span class="summary-value">{{ number_format($order_amount, 2, ',', ' ') }} €</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Date de notification :</span>
                    <span class="summary-value">{{ $notification_date }}</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Statut :</span>
                    <span class="summary-value status">Prête pour le retrait</span>
                </div>
            </div>

            <!-- Pickup Box -->
            <div class="pickup-box">
                <h4>Adresse de retrait</h4>
                <div class="pickup-address">
                    <div>{{ $shop_address_street }}</div>
                    <div>{{ $shop_address_postal_code }} {{ $shop_address_city }}</div>
                    <div>{{ $shop_address_country }}</div>
                </div>
                <div class="pickup-instructions">
                    Merci de vous présenter avec une pièce d’identité pour récupérer votre commande.
                </div>
            </div>

            <!-- Important Note -->
            <div class="important-note">
                <h4>Informations importantes</h4>
                <p>
                    • Votre commande est disponible dès maintenant<br>
                    • Merci d’apporter une pièce d’identité lors du retrait<br>
                    • En cas d’empêchement, contactez-nous pour reprogrammer<br>
                    • La commande sera conservée pendant 7 jours
                </p>
            </div>

            <!-- Shop Info -->
            <div class="shop-info">
                <p class="shop-name">{{ $name_store }}</p>
                <p class="shop-subtitle">FAET, votre boutique de confiance</p>
            </div>

            <p>Nous vous remercions pour votre confiance et avons hâte de vous accueillir pour le retrait de votre commande.</p>

            <p>Cordialement,<br><strong>Boutique {{ $name_store }}</strong></p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
            <p style="margin-top: 10px;">© {{ date('Y') }} {{ $name_store }}. Tous droits réservés.</p>
        </div>
    </div>
</body>
</html>