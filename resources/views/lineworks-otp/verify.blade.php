@extends('layouts.simple')

@section('body')
    <div class="container very-small py-xl">
        <div class="card content-wrap auto-height">
            <h1 class="list-heading">{{ trans('lineworks_otp.title') }}</h1>
            <p class="mb-none">{{ trans('lineworks_otp.description') }}</p>

            @if(session('status'))
                <hr class="my-l">
                <p class="text-pos">{{ session('status') }}</p>
            @endif

            @if(isset($error))
                <hr class="my-l">
                <p class="text-neg">{{ $error }}</p>
            @else
                <hr class="my-l">

                <form action="{{ url('/lineworks-otp/verify') }}" method="post" autocomplete="off">
                    {{ csrf_field() }}

                    <div class="form-group">
                        <label for="code">{{ trans('lineworks_otp.code_label') }}</label>
                        <input type="text"
                               id="code"
                               name="code"
                               autocomplete="one-time-code"
                               autofocus
                               maxlength="6"
                               pattern="[0-9]{6}"
                               inputmode="numeric"
                               placeholder="{{ trans('lineworks_otp.code_placeholder') }}"
                               class="input-fill-width {{ $errors->has('code') ? 'neg' : '' }}">
                        @if($errors->has('code'))
                            <div class="text-neg text-small px-xs mt-xs">{{ $errors->first('code') }}</div>
                        @endif
                    </div>

                    <p class="text-muted text-small">{{ trans('lineworks_otp.expires_in') }}</p>

                    <div class="mt-m text-right">
                        <button type="submit" class="button">{{ trans('lineworks_otp.verify_button') }}</button>
                    </div>
                </form>

                <hr class="my-l">

                <div class="text-center">
                    <form action="{{ url('/lineworks-otp/resend') }}" method="post" class="inline">
                        {{ csrf_field() }}
                        <button type="submit" class="text-button text-muted hover-underline">
                            {{ trans('lineworks_otp.resend_button') }}
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@stop
