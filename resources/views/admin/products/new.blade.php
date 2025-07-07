@extends('layouts.admin')

@section('title')
    New Product
@endsection

@section('content-header')
    <h1>Create Product<small>Create a new server product.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.products') }}">Products</a></li>
        <li class="active">New</li>
    </ol>
@endsection

@section('content')
    <form action="{{ route('admin.products') }}" method="POST">
        {!! csrf_field() !!}
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border"><h3 class="box-title">Product Details</h3></div>
                    <div class="box-body">
                        <div class="form-group">
                            <label for="name" class="control-label">Name</label>
                            <input type="text" id="name" name="name" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label for="description" class="control-label">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <label>CPU %</label>
                                <input type="number" name="cpu" class="form-control" min="1" />
                            </div>
                            <div class="col-md-4">
                                <label>RAM (MB)</label>
                                <input type="number" name="memory" class="form-control" min="1" />
                            </div>
                            <div class="col-md-4">
                                <label>Disk (MB)</label>
                                <input type="number" name="disk" class="form-control" min="1" />
                            </div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div class="col-md-4">
                                <label>Swap (MB)</label>
                                <input type="number" name="swap" class="form-control" />
                            </div>
                            <div class="col-md-4">
                                <label>Credits</label>
                                <input type="number" name="credits" class="form-control" min="0" />
                            </div>
                            <div class="col-md-4">
                                <label>Billing Cycle</label>
                                <select name="billing_cycle" class="form-control">
                                    <option value="hourly">Hourly</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly" selected>Monthly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Max per User</label>
                                <input type="number" name="max_per_user" class="form-control" min="1" />
                            </div>
                        </div>
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
                                <option value="{{ $nest->id }}">{{ $nest->name }}</option>
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
                                        <option value="{{ $node->id }}">{{ $node->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="text-muted small">Select one or more nodes where this product can be deployed.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 text-right">
                <button class="btn btn-success">Create Product</button>
            </div>
        </div>
    </form>
@endsection

@section('footer-scripts')
    @parent
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