<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page_title ?? 'Reset password' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif;
            background-color: #0d1117;
            color: #f0f6fc;
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background-color: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 32px;
            width: 100%;
            max-width: 340px;
            box-shadow: 0 8px 24px rgba(140, 149, 159, 0.2);
        }

        .form-title {
            font-size: 24px;
            font-weight: 300;
            text-align: center;
            margin-bottom: 20px;
            color: #f0f6fc;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #f0f6fc;
        }

        .form-input {
            width: 100%;
            padding: 8px 12px;
            font-size: 14px;
            line-height: 20px;
            background-color: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            color: #f0f6fc;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-input:focus {
            outline: none;
            border-color: #58a6ff;
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.3);
        }

        .btn {
            width: 100%;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            line-height: 20px;
            border: 1px solid;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.15s ease-in-out;
        }

        .btn-primary {
            background-color: #238636;
            border-color: #238636;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #2ea043;
            border-color: #2ea043;
        }

        .back-link {
            display: block;
            margin-top: 16px;
            text-align: center;
            font-size: 12px;
            color: #58a6ff;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .errors-container {
            margin-bottom: 16px;
        }

        .error-message {
            background-color: #490202;
            border: 1px solid #f85149;
            color: #ffa198;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .error-message:last-child {
            margin-bottom: 0;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 16px;
                padding: 24px;
            }
        }
    </style>
</head>
<body>
<div class="login-container">
    <h1 class="form-title">Choose a new password</h1>

    @if(!empty($errors))
        <div class="errors-container">
            @foreach($errors as $field => $field_errors)
                @foreach($field_errors as $error_message)
                    <div class="error-message">
                        @if($field === 'general')
                            {{ $error_message }}
                        @else
                            <strong>{{ ucfirst($field) }}:</strong> {{ $error_message }}
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif

    <form action="/reset-password" method="post">
        @csrf
        <input type="hidden" name="token" value="{{ $token ?? '' }}">

        <div class="form-group">
            <label for="password" class="form-label">New password</label>
            <input type="password"
                   id="password"
                   name="password"
                   class="form-input"
                   placeholder="Enter your new password"
                   required>
        </div>

        <div class="form-group">
            <label for="confirm_password" class="form-label">Confirm new password</label>
            <input type="password"
                   id="confirm_password"
                   name="confirm_password"
                   class="form-input"
                   placeholder="Re-enter your new password"
                   required>
        </div>

        <button type="submit" class="btn btn-primary">Reset password</button>

        <a href="/login" class="back-link">Back to sign in</a>
    </form>
</div>
</body>
</html>
