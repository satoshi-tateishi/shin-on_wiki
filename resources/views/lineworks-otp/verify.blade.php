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

                <form action="{{ url('/lineworks-otp/verify') }}" method="post" autocomplete="off" id="otp-form">
                    {{ csrf_field() }}

                    <div class="form-group">
                        <label>{{ trans('lineworks_otp.code_label') }}</label>
                        <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 8px;">
                            <input type="text" data-digit="0" autocomplete="one-time-code" autofocus maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                            <input type="text" data-digit="1" autocomplete="off" maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                            <input type="text" data-digit="2" autocomplete="off" maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                            <span style="font-size: 1.5rem; color: #888;">-</span>
                            <input type="text" data-digit="3" autocomplete="off" maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                            <input type="text" data-digit="4" autocomplete="off" maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                            <input type="text" data-digit="5" autocomplete="off" maxlength="6" inputmode="numeric" class="otp-digit {{ $errors->has('code') ? 'neg' : '' }}" required>
                        </div>
                        <input type="hidden" id="code" name="code">
                        @if($errors->has('code'))
                            <div class="text-neg text-small px-xs mt-xs">{{ $errors->first('code') }}</div>
                        @endif
                    </div>

                    <style>
                        .otp-digit {
                            width: 36px !important;
                            min-width: 36px !important;
                            max-width: 36px !important;
                            height: 44px !important;
                            text-align: center !important;
                            font-size: 1.5rem !important;
                            line-height: 44px !important;
                            font-family: monospace !important;
                            border: 1px solid #ccc !important;
                            border-radius: 6px !important;
                            flex: 0 0 36px !important;
                            padding: 0 !important;
                            box-sizing: border-box !important;
                        }
                        .otp-digit:focus {
                            outline: none;
                            border-color: #206ea7;
                            box-shadow: 0 0 0 2px rgba(32, 110, 167, 0.2);
                        }
                        .otp-digit.neg {
                            border-color: #b91c1c;
                        }
                    </style>

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

@push('body-end')
<script nonce="{{ $cspNonce ?? '' }}">
(function() {
    const inputs = document.querySelectorAll('.otp-digit');
    const hiddenInput = document.getElementById('code');

    if (!inputs.length || !hiddenInput) return;

    function updateHiddenInput() {
        let code = '';
        inputs.forEach(input => code += input.value);
        hiddenInput.value = code;
    }

    function focusNext(index) {
        if (index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    }

    function focusPrev(index) {
        if (index > 0) {
            inputs[index - 1].focus();
        }
    }

    inputs.forEach((input, index) => {
        input.addEventListener('input', function(e) {
            let value = this.value.replace(/[^0-9]/g, '');
            this.value = value.substring(0, 1);
            updateHiddenInput();
            if (value) {
                focusNext(index);
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value) {
                focusPrev(index);
            }
        });

        input.addEventListener('focus', function() {
            this.select();
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const digits = paste.replace(/[^0-9]/g, '').substring(0, 6).split('');
            digits.forEach((char, i) => {
                if (inputs[i]) inputs[i].value = char;
            });
            updateHiddenInput();
            const nextIndex = Math.min(digits.length, inputs.length - 1);
            inputs[nextIndex].focus();
        });
    });
})();
</script>
@endpush
