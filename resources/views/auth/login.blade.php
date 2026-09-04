{{-- Placeholder until Stage 4 replaces it with the Inertia page. Deliberately unstyled: its only
     job is to make the auth flow exercisable in a browser while the backend is being built. --}}
<x-auth-layout title="Sign in">
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
        <label>Password <input type="password" name="password" required></label>
        <label><input type="checkbox" name="remember"> Remember me</label>
        <button type="submit">Sign in</button>
    </form>
    <p>No account? <a href="{{ route('register') }}">Register</a></p>
</x-auth-layout>
