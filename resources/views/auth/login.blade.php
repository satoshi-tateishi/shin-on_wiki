@extends('layouts.simple')

@section('content')

<style>
    .mobile-menu-toggle {
        display: none !important;
    }
</style>

    <div class="container very-small">

        <div class="my-l">&nbsp;</div>

        <div class="card content-wrap auto-height" style="display: flex; align-items: center; justify-content: center; padding: 30px 60px;">
            {{-- ログイン見出しを削除
            <h1 class="list-heading">{{ Str::title(trans('auth.log_in')) }}</h1>
            --}}

            @include('auth.parts.login-message')

            @include('auth.parts.login-form-' . $authMethod)

            @if(count($socialDrivers) > 0)
                <hr class="my-l">
                @foreach($socialDrivers as $driver => $name)
                    <div>
                        <a id="social-login-{{$driver}}" class="button outline svg" href="{{ url("/login/service/" . $driver) }}">
                            @icon('auth/' . $driver)
                            <span>{{ trans('auth.log_in_with', ['socialDriver' => $name]) }}</span>
                        </a>
                    </div>
                @endforeach
            @endif

            @if(setting('registration-enabled') && config('auth.method') === 'standard')
                <div class="text-center pb-s">
                    <hr class="my-l">
                    <a href="{{ url('/register') }}">{{ trans('auth.dont_have_account') }}</a>
                </div>
            @endif
        </div>
    </div>

@stop
