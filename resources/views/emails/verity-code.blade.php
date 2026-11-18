<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de vérification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4; font-family:Arial, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center" style="padding:20px;">
                <!-- Container -->
                <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#000000; padding:20px; color:#ffffff; text-align:center;">
                            <h1 style="margin:0; font-size:24px;">Confirmez votre e‑mail</h1>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:30px; color:#333333; font-size:16px; line-height:1.5;">
                            <p>Bonjour {{ $user->first_name }},</p>
                            <p>Bienvenue ! Votre inscription est presque terminée.  
                            Pour valider votre compte, saisissez le code ci-dessous dans l’application :</p>
                            
                            <!-- Code box -->
                            <p style="text-align:center; margin:30px 0;">
                                <span style="
                                    display:inline-block;
                                    font-size:32px;
                                    letter-spacing:4px;
                                    padding:10px 20px;
                                    background-color:#f0f4ff;
                                    border:2px dashed #1d4ed8;
                                    border-radius:4px;
                                    color:#1d4ed8;
                                    font-family:monospace;
                                ">
                                    {{ $user->email_verification_code }}
                                </span>
                            </p>

                            <p>Ce code est valable <strong>5 minutes</strong>. Après expiration, demandez un nouveau code dans l’application.</p>
                            <p>Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer ce message.</p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f9fafb; padding:20px; text-align:center; font-size:12px; color:#888888;">
                            <p style="margin:0;">&copy; {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.</p>
                            <!-- <p style="margin:5px 0 0;">123 Rue Exemple, 75000 Paris, France</p> -->
                        </td>
                    </tr>

                </table>
                <!-- /Container -->
            </td>
        </tr>
    </table>
</body>
</html>
