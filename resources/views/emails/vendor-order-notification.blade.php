<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle(s) vente(s)</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #10b981; padding: 20px; border-radius: 8px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0 0 10px 0; font-size: 20px;">🎉 Nouvelle(s) vente(s) !</h1>
                            <p style="color: #f0fdf4; margin: 0; font-size: 16px;">{{ config('app.name') }} - Espace Vendeur</p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                            <h2 style="color: #333333; margin-top: 0;">Bonjour {{ $emailData['vendor_name'] }},</h2>
                            
                            <p style="color: #333333; line-height: 1.6;">
                                Félicitations ! Vous avez reçu 
                                <strong>{{ $emailData['orders_count'] }} nouvelle{{ $emailData['orders_count'] > 1 ? 's' : '' }} commande{{ $emailData['orders_count'] > 1 ? 's' : '' }}</strong>
                                le {{ $emailData['payment_date'] }}.
                            </p>

                            <!-- Orders Summary -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f0fdf4; border-radius: 5px; border: 1px solid #86efac; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <h3 style="margin-top: 0; color: #166534;">Résumé des ventes</h3>
                                        
                                        @foreach($emailData['orders'] as $order)
                                        <div style="background-color: white; padding: 12px; border-radius: 5px; margin-bottom: 10px; border-left: 3px solid #10b981;">
                                            <p style="margin: 5px 0;">
                                                <strong>Commande :</strong> {{ $order['order_number'] }}
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Client :</strong> {{ $order['customer_name'] }}
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Articles :</strong> {{ $order['items_count'] }}
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Montant :</strong> {{ number_format($order['total_amount'], 2, ',', ' ') }} €
                                            </p>
                                        </div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>

                            <!-- Financial Summary -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 5px; border: 1px solid #e9ecef; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <h3 style="margin-top: 0;">💰 Répartition financière</h3>
                                        
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="padding: 5px 0; color: #333333;">Montant brut des ventes :</td>
                                                <td align="right" style="padding: 5px 0; color: #333333; font-weight: bold;">
                                                    {{ number_format($emailData['total_gross'], 2, ',', ' ') }} €
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 5px 0; color: #666666;">
                                                    Commission plateforme ({{ number_format($emailData['platform_fee_rate'], 1) }}%) :
                                                </td>
                                                <td align="right" style="padding: 5px 0; color: #666666;">
                                                    - {{ number_format($emailData['platform_fee'], 2, ',', ' ') }} €
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="border-top: 2px solid #e9ecef; padding-top: 10px;"></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 5px 0; color: #166534; font-weight: bold; font-size: 16px;">
                                                    Montant net à recevoir :
                                                </td>
                                                <td align="right" style="padding: 5px 0; color: #166534; font-weight: bold; font-size: 16px;">
                                                    {{ number_format($emailData['net_amount'], 2, ',', ' ') }} €
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Bon de commande info -->
                            <div style="background-color: #eff6ff; border: 1px solid #93c5fd; border-radius: 5px; padding: 15px; margin: 20px 0;">
                                <p style="margin: 0 0 10px 0; color: #1e40af; font-weight: bold;">
                                    📦 Bon de commande joint
                                </p>
                                <p style="margin: 0; color: #1e40af; font-size: 14px;">
                                    Vous trouverez le détail complet des commandes dans le PDF joint à cet email.
                                </p>
                            </div>

                            <h3 style="color: #333333;">Actions à effectuer</h3>
                            <ol style="color: #333333; line-height: 1.8; padding-left: 20px;">
                                <li>Consultez le bon de commande en pièce jointe</li>
                                <li>Préparez les articles commandés</li>
                                <li>Marquez la commande comme "Prête" dans votre espace vendeur</li>
                                <li>Le client sera automatiquement notifié</li>
                            </ol>

                            <div style="text-align: center; margin-top: 30px;">
                                <a href="{{ config('app.vendor_web_url') }}/order" style="display: inline-block; background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px;">
                                    Accéder à mon espace vendeur
                                </a>
                            </div>

                            <h3 style="color: #333333; margin-top: 30px;">Informations utiles</h3>
                            <p style="color: #333333; line-height: 1.6;">
                                💳 <strong>Paiement :</strong> Le transfert sera effectué selon les modalités convenues.<br>
                                📧 <strong>Support :</strong> <a href="mailto:{{ $emailData['support_email'] }}" style="color: #10b981; text-decoration: none;">{{ $emailData['support_email'] }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Footer -->
                    <tr>
                        <td style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef; color: #888888; font-size: 14px;">
                            <p style="margin: 5px 0;">{{ config('app.name') }} - Espace Vendeur</p>
                            <p style="margin: 5px 0;">Cet email a été envoyé automatiquement.</p>
                            <p style="margin: 5px 0;">Support vendeur : {{ $emailData['support_email'] }}</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>