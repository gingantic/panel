@extends('layouts.admin')

@section('title')
    Product #{{ $product->id }}
@endsection

@section('content-header')
    <h1>{{ $product->name }}<small>Manage product details.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.products') }}">Products</a></li>
        <li class="active">{{ $product->name }}</li>
    </ol>
@endsection

@section('content')
    <form action="{{ route('admin.products.view', $product->id) }}" method="POST">
        {!! csrf_field() !!}
        {!! method_field('PATCH') !!}
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">Product Details</h3></div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="control-label">Status</label>
                            <div>
                                <label class="switch">
                                    <input type="hidden" name="disabled" value="1">
                                    <input type="checkbox" name="disabled" value="0" {{ !$product->disabled ? 'checked' : '' }} style="display: none;">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name" class="form-control" value="{{ $product->name }}" />
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3">{{ $product->description }}</textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4"><label>CPU %</label><input type="number" name="cpu" class="form-control" value="{{ $product->cpu }}" /></div>
                            <div class="col-md-4"><label>RAM (MB)</label><input type="number" name="memory" class="form-control" value="{{ $product->memory }}" /></div>
                            <div class="col-md-4"><label>Disk (MB)</label><input type="number" name="disk" class="form-control" value="{{ $product->disk }}" /></div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div class="col-md-4"><label>Swap (MB)</label><input type="number" name="swap" class="form-control" value="{{ $product->swap }}" /></div>
                            <div class="col-md-4"><label>Credits</label><input type="number" name="credits" class="form-control" value="{{ $product->credits }}" /></div>
                            <div class="col-md-4">
                                <label>Billing Cycle</label>
                                <select name="billing_cycle" class="form-control">
                                    <option value="hourly" {{ $product->billing_cycle === 'hourly' ? 'selected' : '' }}>Hourly</option>
                                    <option value="daily" {{ $product->billing_cycle === 'daily' ? 'selected' : '' }}>Daily</option>
                                    <option value="weekly" {{ $product->billing_cycle === 'weekly' ? 'selected' : '' }}>Weekly</option>
                                    <option value="monthly" {{ $product->billing_cycle === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                    <option value="yearly" {{ $product->billing_cycle === 'yearly' ? 'selected' : '' }}>Yearly</option>
                                </select>
                            </div>
                            <div class="col-md-4"><label>Max per User</label><input type="number" name="max_per_user" class="form-control" value="{{ $product->max_per_user }}" /></div>
                        </div>

                    </div>
                    <div class="box-footer">
                        <button class="btn btn-success pull-right">Save Changes</button>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Allowed Nests</h3>
                        <div class="box-tools">
                            <button type="button" class="btn btn-xs btn-default" data-action="select-nests-all">Select All</button>
                            <button type="button" class="btn btn-xs btn-default" data-action="deselect-nests-all">Deselect All</button>
                        </div>
                    </div>
                    <div class="box-body">
                        <select id="pNests" name="nests[]" class="form-control" multiple>
                            @foreach ($nests as $nest)
                                <option value="{{ $nest->id }}" {{ $product->nests->contains('id', $nest->id) ? 'selected' : '' }}>{{ $nest->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-muted small">Select one or more nests this product is compatible with.</p>
                    </div>
                </div>

                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Allowed Nodes</h3>
                        <div class="box-tools">
                            <button type="button" class="btn btn-xs btn-default" data-action="select-nodes-all">Select All</button>
                            <button type="button" class="btn btn-xs btn-default" data-action="deselect-nodes-all">Deselect All</button>
                        </div>
                    </div>
                    <div class="box-body">
                        <select id="pNodes" name="nodes[]" class="form-control" multiple>
                            @foreach ($locations as $location)
                                <optgroup label="{{ $location->long }} ({{ $location->short }})">
                                    @foreach ($location->nodes as $node)
                                        <option value="{{ $node->id }}" {{ $product->nodes->contains('id', $node->id) ? 'selected' : '' }}>{{ $node->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="text-muted small">Select one or more nodes where this product can be deployed.</p>
                    </div>
                </div>

                <div class="box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Danger Zone</h3></div>
                    <div class="box-body">
                        <p class="text-muted">Deleting this product is permanent and cannot be undone.</p>
                        <button type="submit" name="action" value="delete" class="btn btn-danger btn-block">Delete Product</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('footer-scripts')
    @parent
    <style>
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            margin-right: 8px;
            vertical-align: middle;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .3s;
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            -webkit-transition: .3s;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #28a745;
        }

        input:focus + .slider {
            box-shadow: 0 0 1px #28a745;
        }

        input:checked + .slider:before {
            -webkit-transform: translateX(20px);
            -ms-transform: translateX(20px);
            transform: translateX(20px);
        }

        .switch-label {
            vertical-align: middle;
            font-weight: normal;
            margin-left: 5px;
            color: #333;
            font-size: 14px;
        }
    </style>
    <script>
        $(document).ready(function () {
            $('#pNests').select2({ placeholder: 'Select nests..' });
            $('#pNodes').select2({ placeholder: 'Select nodes..' });

            $('[data-action="select-nests-all"]').click(function () {
                var all = $('#pNests option').map(function () { return $(this).val(); }).get();
                $('#pNests').val(all).trigger('change');
            });
            $('[data-action="deselect-nests-all"]').click(function () {
                $('#pNests').val(null).trigger('change');
            });
            $('[data-action="select-nodes-all"]').click(function () {
                var all = $('#pNodes option').map(function () { return $(this).val(); }).get();
                $('#pNodes').val(all).trigger('change');
            });
            $('[data-action="deselect-nodes-all"]').click(function () {
                $('#pNodes').val(null).trigger('change');
            });
        });
    </script>
@endsection 