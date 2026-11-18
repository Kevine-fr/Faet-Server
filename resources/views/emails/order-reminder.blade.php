<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rappel - Votre commande vous attend</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #ffffff;
            color: #000000;
            line-height: 1.6;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: #000000;
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
        }

        .reminder-box {
            background-color: #000000;
            border: 1px solid #000000;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: center;
            color: white;
        }

        .reminder-title {
            font-size: 20px;
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
        }

        .reminder-message {
            color: white;
            font-size: 16px;
            margin: 0;
        }

        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #000000;
        }

        .message-text {
            font-size: 16px;
            margin-bottom: 25px;
            color: #000000;
        }

        .order-details {
            background-color: #f5f5f5;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #dcdcdc;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #000000;
        }

        .detail-value {
            color: #000000;
            font-weight: 500;
        }

        .store-info {
            background-color: #000000;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
            color: white;
        }

        .store-title {
            font-size: 18px;
            font-weight: 600;
            color: white;
            margin-bottom: 15px;
        }

        .address {
            color: white;
            margin-bottom: 5px;
        }

        .urgent-note {
            background-color: #000000;
            border: 1px solid #000000;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
            color: white;
        }

        .urgent-text {
            color: white;
            font-weight: 600;
            margin: 0;
        }

        .cta-section {
            text-align: center;
            margin: 30px 0;
        }

        .cta-text {
            font-size: 16px;
            color: #000000;
            margin-bottom: 20px;
        }

        .footer {
            background-color: #000000;
            padding: 30px;
            text-align: center;
            color: #ffffff;
            font-size: 14px;
        }

        .footer-text {
            margin: 10px 0;
        }

        @media only screen and (max-width: 600px) {
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

            .order-details, .store-info {
                padding: 20px;
            }

            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Rappel de récupération</h1>
            <p>Votre commande vous attend chez {{ $name_store }}</p>
        </div>

        <div class="content">
            <div class="reminder-box">
                <div class="reminder-title">N'oubliez pas votre commande !</div>
                <div class="reminder-message">
                    Votre commande est prête depuis {{ $days_waiting }} jour(s)
                </div>
            </div>

            <div class="greeting">
                Bonjour {{ $customer_name }},
            </div>

            <div class="message-text">
                Nous espérons que vous allez bien ! Nous vous écrivons pour vous rappeler que votre commande est prête et vous attend dans notre magasin depuis le <strong>@if($pickup_ready_date == 0)Aujourd'hui@else{{ $pickup_ready_date }}@endif</strong>.
            </div>

            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Numéro de commande</span>
                    <span class="detail-value">#{{ $order_number }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Montant</span>
                    <span class="detail-value">{{ number_format($order_amount, 2) }}€</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Prête depuis le</span>
                    <span class="detail-value">@if($pickup_ready_date == 0)Aujourd'hui@else{{ $pickup_ready_date }}@endif</span>
                </div>
            </div>

            <div class="store-info">
                <div class="store-title">Adresse de récupération</div>
                <div class="address"><strong>{{ $name_store }}</strong></div>
                <div class="address">{{ $shop_address_street }}</div>
                <div class="address">{{ $shop_address_postal_code }} {{ $shop_address_city }}</div>
                <div class="address">{{ $shop_address_country }}</div>
            </div>

            @if($days_waiting >= 7)
            <div class="urgent-note">
                <p class="urgent-text">
                    Attention : Votre commande est en attente depuis {{ $days_waiting }} jours. 
                    Merci de la récupérer dans les plus brefs délais pour éviter tout désagrément.
                </p>
            </div>
            @endif

            <div class="cta-section">
                <div class="cta-text">
                    Merci de venir récupérer votre commande dès que possible.
                </div>
            </div>

            <div class="message-text">
                Si vous avez des questions ou si vous rencontrez des difficultés pour récupérer votre commande, n'hésitez pas à nous contacter.
            </div>

            <div class="message-text">
                Merci de votre confiance et à bientôt !
            </div>
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