<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande validée</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5; /* Fond clair */
            color: #111; /* Texte foncé */
            line-height: 1.6;
        }

        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #fff; /* Fond principal blanc */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        /* HEADER */
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
            opacity: 0.8;
        }

        .content {
            padding: 40px 30px;
        }

        /* ✅ Success box en vert */
        .success-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .success-icon {
            width: 24px;
            height: 24px;
            background-color: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }

        .success-content h2 {
            margin: 0 0 10px 0;
            color: #059669;
            font-size: 18px;
            font-weight: 600;
        }

        .success-content p {
            margin: 0;
            color: #065f46;
            font-size: 14px;
        }

        /* Détails commande → fond noir, texte blanc */
        .order-details {
            background-color: #000;
            border-radius: 8px;
            padding: 25px;
            margin: 30px 0;
            color: #fff;
        }

        .order-details h3 {
            margin: 0 0 20px 0;
            color: #fff;
            font-size: 18px;
            font-weight: 600;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #333;
        }

        .detail-row:last-child {
            border-bottom: none;
            font-weight: 600;
        }

        .detail-label {
            color: #ccc;
            font-size: 14px;
        }

        .detail-value {
            color: #fff;
            font-weight: 500;
            text-align: right;
        }

        /* Bloc date retrait → fond gris foncé, texte blanc */
        .pickup-box {
            background: #1a1a1a;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            color: #fff;
            text-align: center;
        }

        .pickup-box h4 {
            margin: 0 0 15px 0;
            font-size: 20px;
            font-weight: 600;
        }

        .pickup-date {
            background-color: #000;
            border-radius: 8px;
            padding: 15px;
            font-size: 24px;
            font-weight: bold;
            margin: 15px 0;
            border: 1px solid #444;
        }

        .pickup-instructions {
            font-size: 14px;
            color: #ccc;
            margin-top: 15px;
        }

        /* ✅ Informations importantes → fond noir */
        .important-note {
            background-color: #000;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
            color: #fff;
        }

        .important-note h4 {
            margin: 0 0 10px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .important-note p {
            margin: 0;
            color: #ccc;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Infos boutique → fond clair, texte foncé */
        .shop-info {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f8f8f8;
            border-radius: 8px;
            color: #000;
        }

        .shop-name {
            font-size: 18px;
            font-weight: 600;
            color: #000;
            margin: 0 0 5px 0;
        }

        .shop-subtitle {
            font-size: 14px;
            color: #555;
            margin: 0;
        }

        /* Pied de page noir */
        .footer {
            background-color: #000;
            padding: 30px;
            text-align: center;
            color: #ccc;
        }

        .footer p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
        }

        .footer a {
            color: #10b981;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .header, .content, .footer {
                padding: 20px;
            }
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .pickup-date {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Commande Validée</h1>
            <p>Votre commande est confirmée et en préparation</p>
        </div>

        <div class="content">
            <p>Bonjour <strong>{{ $customer_name }}</strong>,</p>

            <div class="success-box">
                <div class="success-icon">✓</div>
                <div class="success-content">
                    <h2>Excellente nouvelle !</h2>
                    <p>Votre commande a été validée et est maintenant en cours de préparation.</p>
                </div>
            </div>

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
                    <span class="detail-label">Date de validation</span>
                    <span class="detail-value">{{ $validation_date }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Statut</span>
                    <span class="detail-value" style="color: #10b981;">En préparation</span>
                </div>
            </div>

            <div class="pickup-box">
                <h4>Date de retrait</h4>
                <div class="pickup-date">{{ $pickup_date_formatted }}</div>
                <div class="pickup-instructions">
                    Votre commande sera prête à partir de cette date.<br>
                    Merci de vous présenter à la boutique pour la récupérer.
                </div>
            </div>

            <div class="important-note">
                <h4>Informations importantes</h4>
                <p>
                    • Commande disponible à partir du <strong>{{ $pickup_date_formatted }}</strong><br>
                    • Apportez une pièce d'identité<br>
                    • Contactez-nous en cas d'empêchement<br>
                    • Conservation : 7 jours après la date prévue
                </p>
            </div>

            <div class="shop-info">
                <p class="shop-name">{{ $name_store }}</p>
                <p class="shop-subtitle">FAET, votre boutique de confiance</p>
            </div>

            <p>Merci pour votre confiance. À très bientôt !</p>

            <p>
                Cordialement,<br>
                <strong>Boutique {{ $name_store }}</strong>
            </p>
        </div>

        <div class="footer">
            <p>Cet email a été envoyé automatiquement. Ne pas répondre directement.</p>
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $name_store }}. Tous droits réservés.</p>
        </div>
    </div>
</body>
</html>
