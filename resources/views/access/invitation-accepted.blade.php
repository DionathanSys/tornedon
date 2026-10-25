<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convite aceito</title></head>
<body><main style="max-width:640px;margin:60px auto;font-family:sans-serif;padding:20px">
    <h1>Acesso à {{ $company->name }} confirmado</h1><p>Entre no painel autorizado para esta empresa:</p>
    @foreach (\App\Services\Access\PanelAccess::options() as $panel => $label)
        @if (app(\App\Services\Access\UserAccessService::class)->canAccessPanel(auth()->user(), $company, $panel))
            <p><a href="{{ \Filament\Facades\Filament::getPanel($panel)->getUrl($company) }}">{{ $label }}</a></p>
        @endif
    @endforeach
</main></body></html>
