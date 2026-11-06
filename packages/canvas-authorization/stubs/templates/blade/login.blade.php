<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page_title ?? 'Sign in' }}</title>
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

        .logo {
            display: block;
            margin: 0 auto 24px;
            width: 48px;
            height: 48px;
            fill: #f78166;
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

        .form-input::placeholder {
            color: #7d8590;
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

        .btn-primary:active {
            background-color: #1a7f37;
            border-color: #1a7f37;
        }

        .forgot-password {
            display: block;
            margin-top: 16px;
            text-align: center;
            font-size: 12px;
            color: #58a6ff;
            text-decoration: none;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .divider {
            margin: 24px 0;
            text-align: center;
            position: relative;
            color: #7d8590;
            font-size: 12px;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background-color: #30363d;
        }

        .divider span {
            background-color: #161b22;
            padding: 0 16px;
        }

        .signup-link {
            text-align: center;
            margin-top: 16px;
            padding: 16px;
            border: 1px solid #30363d;
            border-radius: 6px;
            font-size: 14px;
        }

        .signup-link a {
            color: #58a6ff;
            text-decoration: none;
            font-weight: 500;
        }

        .signup-link a:hover {
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
    <!-- Logo -->
    <svg class="logo" viewBox="0 0 16 16" version="1.1" aria-hidden="true">
        <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"></path>
    </svg>

    <h1 class="form-title">Sign in to your account</h1>

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

    <form action="{{ $form_action ?? '/login' }}" method="post">
        @csrf
        <div class="form-group">
            <label for="username" class="form-label">Username or email address</label>
            <input type="text"
                   id="username"
                   name="username"
                   class="form-input"
                   value="{{ old('username') }}"
                   placeholder="Enter your username or email"
                   required>
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input type="password"
                   id="password"
                   name="password"
                   class="form-input"
                   placeholder="Enter your password"
                   required>
        </div>

        <button type="submit" class="btn btn-primary">Sign in</button>

        <a href="{{ $forgot_password_url ?? '/forgot-password' }}" class="forgot-password">
            Forgot password?
        </a>
    </form>

    <div class="divider">
        <span>or</span>
    </div>

    <div class="signup-link">
        New to our platform? <a href="{{ $signup_url ?? '/register' }}">Create an account</a>
    </div>
</div>
</body>
</html>
