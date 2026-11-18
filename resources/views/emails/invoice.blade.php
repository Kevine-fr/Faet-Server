<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre facture {{ $invoiceNumber }}</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <!-- Container principal -->
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #000000; padding: 20px; border-radius: 8px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0 0 10px 0; font-size: 20px;">{{ $companyName }}</h1>
                            <p style="color: #efefef; margin: 0;">Votre facture est prête</p>
                        </td>
                    </tr>

                    <!-- Spacing -->
                    <tr><td height="30"></td></tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                            <h2 style="color: #333333; margin-top: 0;">Bonjour {{ $customerName }},</h2>
                            
                            <p style="color: #333333; line-height: 1.6;">
                                Merci pour votre commande ! Votre facture est maintenant disponible.
                            </p>

                            <!-- Invoice details box -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 5px; border: 1px solid #e9ecef; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <p style="margin: 5px 0;">
                                            <strong>Facture :</strong> 
                                            <span style="color: #000000; font-weight: bold;">{{ $invoiceNumber }}</span>
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Commande :</strong> {{ $orderNumber }}
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Montant :</strong> 
                                            <span style="color: #000000; font-weight: bold;">{{ $formattedAmount }}</span>
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Statut :</strong> Payée ✅
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="color: #333333; line-height: 1.6;">
                                Vous trouverez votre facture en pièce jointe de cet email au format PDF.
                            </p>

                            <p style="color: #333333; line-height: 1.6;">
                                Cette facture fait office de reçu pour votre achat et peut être utilisée à des fins comptables.
                            </p>

                            <h3 style="color: #333333;">Besoin d'aide ?</h3>
                            <p style="color: #333333; line-height: 1.6;">
                                Si vous avez des questions concernant cette facture ou votre commande, n'hésitez pas à nous contacter :
                            </p>
                            <p style="color: #333333;">
                                📧 Email : <a href="mailto:{{ $supportEmail }}" style="color: #000000; text-decoration: none;">{{ $supportEmail }}</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Spacing -->
                    <tr><td height="30"></td></tr>

                    <!-- Footer -->
                    <tr>
                        <td style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef; color: #888888; font-size: 14px;">
                            <p style="margin: 5px 0;">{{ $companyName }}</p>
                            <p style="margin: 5px 0;">Cet email a été envoyé automatiquement, merci de ne pas y répondre directement.</p>
                            <p style="margin: 5px 0;">Pour toute question, utilisez l'adresse : {{ $supportEmail }}</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>