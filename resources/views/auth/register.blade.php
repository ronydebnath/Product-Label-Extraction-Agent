{{-- Placeholder until Stage 4 replaces it with the Inertia page. See auth/login.blade.php. --}}
<x-auth-layout title="Create an account">
    <form method="POST" action="{{ route('register.store') }}">
        @csrf
        <label>Name <input type="text" name="name" value="{{ old('name') }}" required autofocus></label>
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Password <input type="password" name="password" required></label>
        <label>Confirm password <input type="password" name="password_confirmation" required></label>
        <button type="submit">Create account</button>
    </form>
    <p>Already registered? <a href="{{ route('login') }}">Sign in</a></p>
</x-auth-layout>
