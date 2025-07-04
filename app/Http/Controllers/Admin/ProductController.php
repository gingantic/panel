<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\ProductFormRequest;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Product;
use Pterodactyl\Models\Location;
use Pterodactyl\Repositories\Eloquent\ProductRepository;

class ProductController extends Controller
{
    /**
     * ProductController constructor.
     */
    public function __construct(
        protected AlertsMessageBag $alert,
        protected ProductRepository $repository,
        protected ViewFactory $view,
    ) {
    }

    /**
     * Display product index page.
     */
    public function index(): View
    {
        return $this->view->make('admin.products.index', [
            'products' => $this->repository->getAllWithDetails(),
        ]);
    }

    /**
     * Show create new product form.
     */
    public function create(): View
    {
        return $this->view->make('admin.products.new', [
            'nests' => Nest::all(),
            'nodes' => Node::with('location')->get(),
            'locations' => Location::with('nodes')->get(),
        ]);
    }

    /**
     * Display product view page.
     */
    public function view(Product $product): View
    {
        return $this->view->make('admin.products.view', [
            'product' => $product->load('nests', 'nodes'),
            'nests' => Nest::all(),
            'nodes' => Node::with('location')->get(),
            'locations' => Location::with('nodes')->get(),
        ]);
    }

    /**
     * Handle request to create new product.
     */
    public function store(ProductFormRequest $request): RedirectResponse
    {
        $model = (new Product())->fill($request->normalize());
        $model->saveOrFail();

        // sync nests and nodes if provided
        $nests = $request->input('nests', []);
        if (!empty($nests)) {
            $model->nests()->sync($nests);
        }
        $nodes = $request->input('nodes', []);
        if (!empty($nodes)) {
            $model->nodes()->sync($nodes);
        }

        $this->alert->success('Product was created successfully.')->flash();

        return redirect()->route('admin.products.view', $model->id);
    }

    /**
     * Update or delete a product.
     */
    public function update(ProductFormRequest $request, Product $product): RedirectResponse
    {
        if ($request->input('action') === 'delete') {
            return $this->delete($product);
        }

        $product->fill($request->normalize());
        $product->save();

        // handle nests/nodes updates
        if ($request->has('nests')) {
            $product->nests()->sync($request->input('nests'));
        }
        if ($request->has('nodes')) {
            $product->nodes()->sync($request->input('nodes'));
        }

        $this->alert->success('Product was updated successfully.')->flash();

        return redirect()->route('admin.products.view', $product->id);
    }

    /**
     * Delete a product.
     */
    public function delete(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('admin.products');
    }
} 