<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convite de acesso</title></head>
<body><main style="max-width:640px;margin:60px auto;font-family:sans-serif;padding:20px">
    <h1>Convite para {{ $invitation->company->name }}</h1>
    <p>Convite destinado a {{ $invitation->email }}. Ao aceitar, receberá os papéis autorizados para esta empresa.</p>
    @if ($errors->any())<ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    @if (! auth()->check() && $hasAccount)
        <p><a href="{{ \Filament\Facades\Filament::getPanel('mobile')->getLoginUrl() }}">Entrar com minha conta para aceitar</a></p>
    @else
        <form method="POST" action="{{ route('company-access.invitation.accept', $token) }}">@csrf
            @guest
                <p>Crie sua conta para aceitar. Não é necessário outro cadastro se você já utiliza o sistema.</p>
                <p><label>Nome <input name="name" required maxlength="255" value="{{ old('name') }}" autocomplete="name"></label></p>
                <p><label>Senha <input type="password" name="password" required minlength="8" autocomplete="new-password"></label></p>
                <p><label>Confirmar senha <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label></p>
            @endguest
            <button type="submit">Aceitar convite</button>
        </form>
    @endif
</main></body></html>
