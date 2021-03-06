<?php

namespace Leantony\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Leantony\Database\Extra\CanPaginateCollection;
use Leantony\Database\Extra\Query;
use Leantony\Database\Extra\ViewUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class AbstractRepository
{
    use CanPaginateCollection, ViewUtils, Query;

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        // resolve the model class
        $this->makeModel();
        $this->configureModel();
    }

    /**
     * Set some config vars to the model instance
     * @return void
     */
    public function configureModel()
    {
        $value = $this->getPaginationLimit();
        $this->model = $this->model->setPerPage((int)$value);
    }

    /**
     * Make an instance of the model class specified
     *
     * @return Model
     */
    public function makeModel()
    {
        $model = $this->app->make($this->model());

        if (!$model instanceof Model) {
            throw new InvalidArgumentException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        return $this->model = $model;
    }

    /**
     * Set the model instance
     * ```php
     * public function model(){
     *    return SomeModel::class;
     * }
     * ```
     */
    abstract public function model();

    /**
     * Search a model
     *
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    public function search($paginate = true)
    {
        // pass in the scope we created
        if ($paginate) {
            return $this->getModel()->search()->paginate($this->getPaginationLimit());
        }
        return $this->getModel()->search();
    }

    /**
     * Search a model, with relationships
     *
     * @param array $with
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    public function searchWith(array $with, $paginate = true)
    {
        // pass in the scope we created
        if (!$paginate) {
            return $this->getModel()->search()->with($with);
        }
        return $this->getModel()->search()->with($with)->paginate($this->getPaginationLimit());
    }

    /**
     * Actually returns a Model, but for code hinting, we just say \Eloquent
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->makeModel();
    }

    /**
     * Check if a model exists based on a query
     *
     * @param array $where
     * @return bool
     */
    public function exists(array $where)
    {
        return $this->where($where)->exists();
    }

    /**
     * Distinct results only
     *
     * @param array $where
     * @param array $order
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function distinct(array $where, array $order = [], array $columns = ['*'])
    {
        list($orderColumn, $orderType) = $this->extractSortValues($order);

        return $this->where($where)->orderBy($orderColumn, $orderType)->distinct()->get($columns);
    }

    /**
     * create a new model instance
     *
     * @param $data
     * @return Model
     */
    public function create(array $data)
    {
        return $this->getModel()->create($data);
    }

    /**
     * Fetch all data
     *
     * @param array $columns
     * @param array $order
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all($columns = ['*'], array $order = [])
    {
        list($orderColumn, $orderType) = $this->extractSortValues($order);
        return $this->getModel()->orderBy($orderColumn, $orderType)->get($columns);
    }

    /**
     * Perform a bulk insert
     * Note that this will skip all model events as it uses the DB query builder
     *
     * @param array $data
     * @return bool
     */
    public function createMany(array $data)
    {
        return $this->getModel()->insert($data);
    }

    /**
     * Update many records at once
     *
     * @param array $ids
     * @param array $data
     * @return bool
     */
    public function updateMany(array $ids, array $data)
    {
        $status = $this->getModel()->whereIn($this->getModel()->getKeyName(), $ids)->update($data);
        if (!$status) {
            throw new HttpException(500, 'Unable to bulk update.', null, []);
        }

        return $status;
    }

    /**
     * Delete many records
     *
     * @param array $ids
     * @return bool|null
     */
    public function deleteMany(array $ids)
    {
        $status = $this->getModel()->whereIn($this->getModel()->getKeyName(), $ids)->delete();
        if (!$status) {
            throw new HttpException(500, 'Unable to bulk delete.', null, []);
        }

        return $status;
    }

    /**
     * Find a model based on ID, and update it
     * If the model is an instance of Model, then we directly update
     *
     * @param integer|array|model $id
     * @param array $data
     * @return Model|bool
     * @throws \Exception
     */
    public function update($id, array $data)
    {
        // handle bulks
        if (is_array($id)) {
            return $this->updateMany($id, $data);
        }
        if ($id instanceof Model) {
            $result = $id->update($data);
            $model = $id;
        } else {
            $model = $this->getModel()->findOrFail($id);
            $result = $model->update($data);
        }

        if (!$result) {
            throw new HttpException(500, 'Unable to update.', null, []);
        }
        return $model;
    }

    /**
     * Get the underlying Eloquent query builder instance
     * Use this when a custom query is required, and the existing repository methods don't suffice
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return $this->getModel()->query();
    }

    /**
     * Use a different db connection
     *
     * @param $name
     * @return $this
     */
    public function connectTo($name)
    {
        $this->model = $this->getModel()->setConnection($name);
        return $this;
    }

    /**
     * Get the underlying Database query builder instance
     * For low level functionality
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function db()
    {
        return DB::table($this->getModel()->getTable());
    }

    /**
     * Delete a single record
     *
     * @param int|Model $id
     * @return bool|null
     */
    public function delete($id)
    {
        $result = $id instanceof Model ? $id->delete() : $this->findOne($id)->delete();
        if ($result) {
            return $result;
        }
        throw new HttpException(500, 'Unable to delete.', null, []);
    }

    /**
     * Forcefully delete a model. For soft deletes
     *
     * @param Model|integer $id
     * @return bool|null
     */
    public function forceDelete($id)
    {
        $result = $id instanceof Model ? $id->forceDelete() : $this->findOne($id)->forceDelete();
        if ($result) {
            return $result;
        }
        throw new HttpException(500, 'Unable to force delete.', null, []);
    }

    /**
     * Execute a where condition query
     *
     * @param $condition
     * @return Model
     */
    public function where($condition)
    {
        return $this->getModel()->where($condition);
    }

    /**
     * Load a model builder instance, passing in relationships
     *
     * @param $relations
     * @return Builder|Model
     */
    public function with($relations)
    {
        return $this->getModel()->with($relations);
    }

    /**
     * @return mixed
     */
    protected function getPaginationLimit()
    {
        return $this->app['config']['repository.pagination_limit'];
    }
}