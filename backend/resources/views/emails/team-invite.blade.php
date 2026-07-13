<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your MyIPStrategy Workspace Access</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <h2 style="margin-bottom: 8px;">MyIPStrategy Workspace Invite</h2>
    <p>Hello {{ $name }},</p>
    <p>Your workspace access has been created for MyIPStrategy.</p>
    <p>
        <strong>Login URL:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
        <strong>Email:</strong> {{ $email }}<br>
        <strong>Temporary Password:</strong> {{ $password }}
    </p>
    <p>Please sign in and change your password immediately from Settings.</p>
</body>
</html>
