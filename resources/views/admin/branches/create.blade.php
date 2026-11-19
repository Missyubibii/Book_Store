{{--
    Tạo mới Chi nhánh Bán lẻ (Stage UI-02.2)
    - Entry point: GET /quan-tri/chi-nhanh/tao-moi (`admin.branches.create`) -> POST /quan-tri/chi-nhanh (`admin.branches.store`)
    - Loại chi nhánh bị khóa cố định thành Chi nhánh bán lẻ (`retail_branch`).
    - Không cho phép dùng mã `CENTRAL_WH` hoặc trùng mã hiện có.
--}}
@extends('layouts.admin')

@section('title', 'Tạo Chi nhánh Bán lẻ Mới — Book_Store')
@section('header_title', 'Tạo chi nhánh bán lẻ')

@section('content')
<div class="bs-ad-dashboard-canvas">
    {{-- ==================== 1. PAGE HEADER ==================== --}}
    <x-admin.page-header
        title="Tạo Chi nhánh Bán lẻ"
        subtitle="Mã chi nhánh sẽ được chuẩn hóa thành chữ in hoa và giữ bất biến sau khi tạo để bảo toàn truy vết tồn kho và kiểm toán."
    >
        <x-slot:breadcrumb>
            <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
                @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
                    <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
                    <span aria-hidden="true">/</span>
                @endif
                <a href="{{ route('admin.branches.index') }}">Quản lý chi nhánh</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Tạo chi nhánh mới</span>
            </nav>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Quay lại danh sách</a>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- ==================== 2. CREATE BRANCH FORM ==================== --}}
    <x-admin.form-section
        title="Thông tin chi nhánh bán lẻ"
        :subtitle="'Các trường có dấu (*) là bắt buộc. Kho trung tâm duy nhất (' . ($centralWarehouse?->code ?? 'CENTRAL_WH') . ') được bảo vệ tách biệt và không thể tạo thêm tại đây.'"
        heading-id="create-branch-form-heading"
    >
        <form
            method="POST"
            action="{{ route('admin.branches.store') }}"
            data-admin-form
            novalidate
        >
            @csrf

            <div class="bs-ad-form-grid">
                <x-admin.form-field
                    label="Mã chi nhánh"
                    for="branch-code"
                    :required="true"
                    help="3–32 ký tự chữ in hoa (A–Z), số (0–9), gạch ngang (-) hoặc gạch dưới (_). Cấm dùng mã CENTRAL_WH."
                    :error="$errors->first('code')"
                >
                    <input
                        id="branch-code"
                        type="text"
                        name="code"
                        value="{{ old('code') }}"
                        required
                        maxlength="32"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Ví dụ: HN_CAU_GIAY"
                        data-branch-code-input
                        class="bs-ad-input @error('code') bs-ad-input-error @enderror"
                    >
                </x-admin.form-field>

                <x-admin.form-field
                    label="Tên chi nhánh"
                    for="branch-name"
                    :required="true"
                    help="Tên hiển thị hành chính trong toàn hệ thống (2–150 ký tự)."
                    :error="$errors->first('name')"
                >
                    <input
                        id="branch-name"
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        maxlength="150"
                        autocomplete="organization"
                        placeholder="Ví dụ: Book_Store Cầu Giấy"
                        class="bs-ad-input @error('name') bs-ad-input-error @enderror"
                    >
                </x-admin.form-field>

                <x-admin.form-field
                    label="Loại đơn vị"
                    :error="$errors->first('type')"
                >
                    <div class="bs-ad-readonly-box">
                        <x-admin.status-badge variant="info">Chi nhánh bán lẻ</x-admin.status-badge>
                        <span class="bs-ad-help">Khóa cứng theo nghiệp vụ (không thể tạo Kho trung tâm)</span>
                    </div>
                </x-admin.form-field>

                <x-admin.form-field
                    label="Trạng thái ban đầu"
                    for="branch-status"
                    :error="$errors->first('status')"
                >
                    <select
                        id="branch-status"
                        name="status"
                        class="bs-ad-select @error('status') bs-ad-input-error @enderror"
                    >
                        @foreach ($statusOptions as $option)
                            <option
                                value="{{ $option->value }}"
                                @selected(old('status', \App\Enums\BranchStatus::Active->value) === $option->value)
                            >
                                {{ $option->label() }}
                            </option>
                        @endforeach
                    </select>
                </x-admin.form-field>

                <x-admin.form-field
                    label="Số điện thoại liên hệ"
                    for="branch-phone"
                    :error="$errors->first('phone')"
                >
                    <input
                        id="branch-phone"
                        type="tel"
                        name="phone"
                        value="{{ old('phone') }}"
                        maxlength="30"
                        autocomplete="tel"
                        placeholder="Ví dụ: 024 3888 9999"
                        class="bs-ad-input @error('phone') bs-ad-input-error @enderror"
                    >
                </x-admin.form-field>

                <x-admin.form-field
                    label="Địa chỉ chi nhánh"
                    for="branch-address"
                    :full="true"
                    :error="$errors->first('address')"
                >
                    <textarea
                        id="branch-address"
                        name="address"
                        rows="3"
                        maxlength="500"
                        autocomplete="street-address"
                        placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố..."
                        class="bs-ad-textarea @error('address') bs-ad-input-error @enderror"
                    >{{ old('address') }}</textarea>
                </x-admin.form-field>
            </div>

            <x-admin.form-actions>
                <button type="submit" class="bs-ad-btn-primary">
                    <span>Lưu chi nhánh bán lẻ</span>
                </button>
                <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Hủy thao tác</a>
            </x-admin.form-actions>
        </form>
    </x-admin.form-section>
</div>
@endsection
