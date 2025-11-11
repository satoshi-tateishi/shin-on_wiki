<form action="{{ url('/oidc/login') }}" method="POST" id="login-form" class="mt-l">
    {!! csrf_field() !!}

    <div style="text-align: center;">
        <button id="oidc-login" class="button" style="background-color: #06C755; color: white; border: none; padding: 12px 24px; font-size: 16px; font-weight: 700; border-radius: 6px; margin: 16px 0; text-align: center;">
            <span>{{ trans('auth.log_in_with', ['socialDriver' => config('oidc.name')]) }}</span>
        </button>
    </div>

</form>
