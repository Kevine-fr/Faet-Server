<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Compte Administrateur Créé</title>
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
              <h1 style="margin:0; font-size:22px;">Bienvenue parmi les administrateurs</h1>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:30px; color:#333333; font-size:16px; line-height:1.5;">
              <p>Bonjour <strong>{{ $data['first_name'] }} {{ $data['last_name'] }}</strong>,</p>
              <p>Votre compte administrateur a été créé avec succès.</p>

              <p><strong>Voici vos identifiants :</strong></p>
              <ul style="list-style:none; padding:0;">
                <li><strong>Email :</strong> {{ $data['email'] }}</li>
                <li><strong>Mot de passe :</strong> {{ $data['password'] }}</li>
              </ul>

              <p style="margin-top:20px;">
                Vous pouvez vous connecter dès maintenant à votre espace administrateur en cliquant sur le bouton ci-dessous :
              </p>

              <!-- Bouton de connexion -->
              <p style="text-align:center; margin:30px 0;">
                <a href="https://cockpit.byfaet.fr/" target="_blank" style="
                  display:inline-block;
                  background-color:#1d4ed8;
                  color:#ffffff;
                  text-decoration:none;
                  padding:12px 24px;
                  border-radius:6px;
                  font-weight:bold;
                  font-size:16px;
                ">
                  🔐 Se connecter
                </a>
              </p>

              <p>Nous vous recommandons de changer votre mot de passe dès votre première connexion.</p>

              <p>Merci,</p>
              <p><em>L’équipe de support</em></p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f9fafb; padding:20px; text-align:center; font-size:12px; color:#888888;">
              <p style="margin:0;">&copy; {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.</p>
            </td>
          </tr>
        </table>
        <!-- /Container -->
      </td>
    </tr>
  </table>
</body>
</html>
