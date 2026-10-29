<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\UatAccounts;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (!Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->accountKey(), self::ACCOUNT_WINDOW);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();
        if ($user instanceof User && (!$user->active || ($user->uat_key && !UatAccounts::usable($user)))) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $ipLimited = RateLimiter::tooManyAttempts($this->throttleKey(), 5);
        // Per-account limit as well, so guessing from many addresses is also stopped.
        $accountLimited = RateLimiter::tooManyAttempts($this->accountKey(), self::ACCOUNT_ATTEMPTS);
        if (!$ipLimited && !$accountLimited) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(RateLimiter::availableIn($this->throttleKey()), $accountLimited ? RateLimiter::availableIn($this->accountKey()) : 0);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    /** Failed sign-ins allowed per account in ACCOUNT_WINDOW seconds, from any address. */
    public const ACCOUNT_ATTEMPTS = 10;

    public const ACCOUNT_WINDOW = 900;

    public function accountKey(): string
    {
        return 'login-account:' . Str::transliterate(Str::lower($this->string('email')));
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')) . '|' . $this->ip());
    }
}
