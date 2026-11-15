{{--
    Tạo mới Chi nhánh Bán lẻ (Stage 01B.4)
    - Entry point: GET /quan-tri/chi-nhanh/tao-moi (`admin.branches.create`) -> POST /quan-tri/chi-nhanh (`admin.branches.store`)
    - Loại chi nhánh bị khóa cố định thành Chi nhánh bán lẻ (`retail_branch`).
    - Không cho phép dùng mã `CENTRAL_WH` hoặc trùng mã hiện có.
--}}
@extends('layouts.admin')

@section('title', 'Tạo Chi nhánh Bán lẻ Mới — Book_Store')

@section('content')
    <nav class="bs-ad-breadcrumb" aria-label="Đường dẫn điều hướng">
        @if (auth()->user() && app(\App\Services\Authorization\AuthorizationService::class)->isEffectiveSuperAdmin(auth()->user()))
            <a href="{{ route('admin.dashboard') }}">Tổng quan quản trị</a>
            <span aria-hidden="true">/</span>
        @endif
        <a href="{{ route('admin.branches.index') }}">Chi nhánh bán lẻ</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Tạo chi nhánh mới</span>
    </nav>

    <div class="bs-ad-page-head">
        <div class="bs-ad-page-head-copy">
            <p class="bs-ad-eyebrow">Khởi tạo Điểm bán Mới</p>
            <h1 class="bs-ad-title">Tạo Chi nhánh Bán lẻ</h1>
            <p class="bs-ad-subtitle">
                Mã chi nhánh sẽ được chuẩn hóa thành chữ in hoa và giữ bất biến sau khi tạo để bảo toàn truy vết tồn kho và kiểm toán.
            </p>
        </div>

        <div class="bs-ad-page-actions">
            <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Quay lại danh sách</a>
        </div>
    </div>

    <section class="bs-ad-panel" aria-labelledby="create-branch-form-heading">
        <h2 id="create-branch-form-heading" class="bs-ad-panel-title">Thông tin chi nhánh bán lẻ</h2>
        <p class="bs-ad-panel-subtitle">
            Các trường có dấu (*) là bắt buộc. Kho trung tâm duy nhất ({{ $centralWarehouse?->code ?? 'CENTRAL_WH' }}) được bảo vệ tách biệt và không thể tạo thêm tại đây.
        </p>

        <form
            method="POST"
            action="{{ route('admin.branches.store') }}"
            data-admin-form
            x-data="adminBranchForm"
            @submit="handleSubmit"
            novalidate
        >
            @csrf

            <div class="bs-ad-form-grid">
                <div class="bs-ad-field">
                    <label for="branch-code" class="bs-ad-label">Mã chi nhánh *</label>
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
                        @input="normalizeCodeInput"
                        class="bs-ad-input @error('code') bs-ad-input-error @enderror"
                    >
                    <p class="bs-ad-help">
                        3–32 ký tự chữ in hoa (A–Z), số (0–9), gạch ngang (-) hoặc gạch dưới (_). Cấm dùng mã CENTRAL_WH.
                    </p>
                    @error('code')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="branch-name" class="bs-ad-label">Tên chi nhánh *</label>
                    <input
                        id="branch-name"
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        maxlength="150"
                        placeholder="Ví dụ: Book_Store Cầu Giấy"
                        class="bs-ad-input @error('name') bs-ad-input-error @enderror"
                    >
                    <p class="bs-ad-help">Tên hiển thị hành chính trong toàn hệ thống (2–150 ký tự).</p>
                    @error('name')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <span class="bs-ad-label">Loại đơn vị</span>
                    <div class="bs-ad-readonly-box">
                        <span class="bs-ad-pill bs-ad-pill-neutral">Chi nhánh bán lẻ</span>
                        <span class="bs-ad-help">Khóa cứng theo nghiệp vụ (không thể tạo Kho trung tâm)</span>
                    </div>
                </div>

                <div class="bs-ad-field">
                    <label for="branch-status" class="bs-ad-label">Trạng thái ban đầu</label>
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
                    @error('status')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field">
                    <label for="branch-phone" class="bs-ad-label">Số điện thoại liên hệ</label>
                    <input
                        id="branch-phone"
                        type="tel"
                        name="phone"
                        value="{{ old('phone') }}"
                        maxlength="30"
                        placeholder="Ví dụ: 024 3888 9999"
                        class="bs-ad-input @error('phone') bs-ad-input-error @enderror"
                    >
                    @error('phone')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="bs-ad-field bs-ad-field-full">
                    <label for="branch-address" class="bs-ad-label">Địa chỉ chi nhánh</label>
                    <textarea
                        id="branch-address"
                        name="address"
                        rows="3"
                        maxlength="500"
                        placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố..."
                        class="bs-ad-textarea @error('address') bs-ad-input-error @enderror"
                    >{{ old('address') }}</textarea>
                    @error('address')
                        <p class="bs-ad-field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bs-ad-form-actions">
                <button
                    type="submit"
                    class="bs-ad-btn-primary"
                    :disabled="submitting"
                    :aria-busy="submitting ? 'true' : 'false'"
                >
                    <span x-text="submitting ? 'Đang lưu chi nhánh...' : 'Lưu chi nhánh bán lẻ'">Lưu chi nhánh bán lẻ</span>
                </button>

                <a href="{{ route('admin.branches.index') }}" class="bs-ad-btn-outline">Hủy thao tác</a>
            </div>
        </form>
    </section>
@endsection
