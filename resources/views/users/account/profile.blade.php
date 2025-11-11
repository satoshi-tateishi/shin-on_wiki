@extends('users.account.layout')

@section('main')

    <section class="card content-wrap auto-height">
        <form action="{{ url('/my-account/profile') }}" method="post" enctype="multipart/form-data">
            {{ method_field('put') }}
            {{ csrf_field() }}

            <div class="flex-container-row gap-l items-center wrap justify-space-between">
                <h1 class="list-heading">{{ trans('preferences.profile') }}</h1>
                <div>
                    <a href="{{ user()->getProfileUrl() }}" class="button outline">{{ trans('preferences.profile_view_public') }}</a>
                </div>
            </div>

            <p class="text-muted text-small mb-none">{{ trans('preferences.profile_desc') }}</p>

            <div class="setting-list">

                {{-- 名前フィールド（コメントアウト）
                <div class="flex-container-row gap-l items-center wrap">
                    <div class="flex">
                        <label class="setting-list-label" for="name">{{ trans('auth.name') }}</label>
                        <p class="text-small mb-none">{{ trans('preferences.profile_name_desc') }}</p>
                    </div>
                    <div class="flex stretch-inputs">
                        @include('form.text', ['name' => 'name'])
                    </div>
                </div>
                --}}

                {{-- メールアドレスフィールド（コメントアウト）
                <div>
                    <div class="flex-container-row gap-l items-center wrap">
                        <div class="flex">
                            <label class="setting-list-label" for="email">{{ trans('auth.email') }}</label>
                            <p class="text-small mb-none">{{ trans('preferences.profile_email_desc') }}</p>
                        </div>
                        <div class="flex stretch-inputs">
                            @include('form.text', ['name' => 'email', 'disabled' => !userCan(\BookStack\Permissions\Permission::UsersManage)])
                        </div>
                    </div>
                    @if(!userCan(\BookStack\Permissions\Permission::UsersManage))
                        <p class="text-small text-muted">{{ trans('preferences.profile_email_no_permission') }}</p>
                    @endif
                </div>
                --}}

                @include('users.parts.language-option-row', ['value' => old('language') ?? user()->getLocale()->appLocale()])

            </div>

            <div class="form-group text-right">
                {{-- アカウント削除ボタン（コメントアウト）
                <a href="{{ url('/my-account/delete') }}" class="button outline">{{ trans('preferences.delete_account') }}</a>
                --}}
                <button class="button">{{ trans('common.save') }}</button>
            </div>

        </form>
    </section>

    @if(userCan(\BookStack\Permissions\Permission::UsersManage))
        <section class="card content-wrap auto-height">
            <div class="flex-container-row gap-l items-center wrap">
                <div class="flex">
                    <h2 class="list-heading">{{ trans('preferences.profile_admin_options') }}</h2>
                    <p class="text-small">{{ trans('preferences.profile_admin_options_desc') }}</p>
                </div>
                <div class="text-m-right">
                    <a class="button outline" href="{{ user()->getEditUrl() }}">{{ trans('common.open') }}</a>
                </div>
            </div>
        </section>
    @endif
@stop
