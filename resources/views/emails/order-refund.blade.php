<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remboursement de votre commande - {{ $name_store }}</title>
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
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e5e5;
            overflow: hidden;
        }

        .header {
            background: #000;
            color: #fff;
            text-align: center;
            padding: 40px 30px;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }

        .header p {
            margin: 10px 0 0;
            font-size: 16px;
            opacity: 0.85;
        }

        .content {
            padding: 40px 30px;
        }

        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #000;
            margin-bottom: 20px;
        }

        .message-text {
            font-size: 15px;
            color: #333;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        /* ✅ Boîte succès (seule colorée - vert) */
        .refund-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
        }

        .refund-title {
            font-size: 20px;
            font-weight: 700;
            color: #059669;
            margin-bottom: 8px;
        }

        .refund-message {
            color: #065f46;
            font-size: 15px;
        }

        /* 🧾 Détails commande */
        .order-details {
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .detail-label {
            color: #555;
        }

        .detail-value {
            color: #000;
            font-weight: 600;
        }

        .refund-amount {
            color: #10b981;
            font-weight: 700;
        }

        /* 🕒 Timeline (noir) */
        .timeline-box {
            background: #000;
            color: #fff;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }

        .timeline-title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .timeline-item {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        /* ⚠️ Informations importantes (noir et blanc) */
        .info-box {
            background: #000;
            color: #fff;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }

        .info-title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .info-text {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
        }

        /* 📞 Contact */
        .contact-info {
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
            text-align: center;
        }

        .contact-title {
            font-size: 16px;
            font-weight: 700;
            color: #000;
            margin-bottom: 8px;
        }

        .contact-text {
            font-size: 14px;
            color: #333;
        }

        /* 🦶 Pied de page */
        .footer {
            background: #000;
            color: #999;
            text-align: center;
            padding: 30px 20px;
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

            .refund-box, .info-box, .timeline-box {
                padding: 20px;
            }

            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- HEADER -->
        <div class="header">
            <h1>Remboursement en cours</h1>
            <p>Votre demande de remboursement a été traitée</p>
        </div>

        <!-- CONTENT -->
        <div class="content">
            <!-- ✅ Refund Box -->
            <div class="refund-box">
                <div class="refund-title">Remboursement confirmé</div>
                <div class="refund-message">
                    Votre commande sera remboursée dans les plus brefs délais.
                </div>
            </div>

            <div class="greeting">Bonjour {{ $customer_name }},</div>

            <div class="message-text">
                Nous vous confirmons que votre demande de remboursement pour la commande <strong>#{{ $order_number }}</strong> a été traitée avec succès.
            </div>

            <!-- 🧾 Détails -->
            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Numéro de commande :</span>
                    <span class="detail-value">#{{ $order_number }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Montant à rembourser :</span>
                    <span class="detail-value refund-amount">{{ number_format($refund_amount, 2, ',', ' ') }} €</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date de traitement :</span>
                    <span class="detail-value">{{ $refund_date }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Boutique :</span>
                    <span class="detail-value">{{ $name_store }}</span>
                </div>
            </div>

            <!-- 🕒 Timeline -->
            <div class="timeline-box">
                <div class="timeline-title">Délais de remboursement</div>
                <div class="timeline-item">• Demande traitée — Aujourd’hui</div>
                <div class="timeline-item">• Remboursement initié — Sous 24-48h</div>
                <div class="timeline-item">• Fonds disponibles — 3 à 7 jours ouvrés</div>
            </div>

            <!-- ⚠️ Informations importantes -->
            <div class="info-box">
                <div class="info-title">Informations importantes</div>
                <div class="info-text">
                    Le remboursement sera effectué sur le même moyen de paiement utilisé lors de l'achat.<br>
                    Les délais peuvent varier selon votre banque ou organisme de paiement.<br>
                    Vous recevrez une confirmation une fois le remboursement effectué.
                </div>
            </div>

            <div class="message-text">
                Le montant de <strong>{{ number_format($refund_amount, 2, ',', ' ') }} €</strong> sera crédité sur votre compte dans les délais indiqués ci-dessus.
            </div>

            <!-- 📞 Contact -->
            <div class="contact-info">
                <div class="contact-title">Une question sur votre remboursement ?</div>
                <div class="contact-text">
                    N'hésitez pas à nous contacter si vous avez des questions concernant votre remboursement.<br>
                    Nous sommes là pour vous aider.
                </div>
            </div>

            <div class="message-text">
                Nous vous remercions pour votre confiance et votre compréhension.<br><br>
                Cordialement,<br>
                <strong>Boutique {{ $name_store }}</strong>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre directement.</p>
            <p>© {{ date('Y') }} {{ $name_store }}. Tous droits réservés.</p>
        </div>
    </div>
</body>
</html>
