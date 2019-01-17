<?php

namespace Sphinx\Engine;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Illuminate\Database\Eloquent\SoftDeletes;
use Sphinx\Lib\SphinxClient;

class SphinxEngine extends Engine
{
    
    private $sphinx_client;
    private $attrs;
    private $values;
    private $config;
    private $index_config;
    private $index;
    
    public function __construct($config)
    {
        $host = $config['host'];
        $port = $config['port'];
        $this->sphinx_client = new SphinxClient();
        $this->sphinx_client->SphinxClient();
        $this->sphinx_client->SetServer($host, $port);
        $this->attrs = [];
        $this->values = [];
        $this->config = $config;
    }
    
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }
        
        $index = $models->first()->searchableAs();
        
        if ($this->usesSoftDelete($models->first()) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }
        
        $values = $models->map(function ($model) {
            $array = array_merge(
                $model->toSearchableArray(), $model->scoutMetadata()
            );
            
            $index = $model->first()->searchableAs();
            $array = $this->getattrsFields($index, $array);
            
            if (empty($array)) {
                return;
            }
            
            $key = $model->getScoutKey();
            $this->attrs = array_keys($array);
            $this->values[$key] = array_values($array);
            return;
        });
        
        if (! empty($this->values)) {
            $this->sphinx_client->UpdateAttributes($index, $this->attrs, $this->values);
        }
    }
    
    private function getattrsFields($index, $array){
        if(empty($index) || empty($array)){
            return;
        }
        $this->index_config = $index_config = $this->config['index'][$index];
        if(!isset($this->index_config) || empty($this->index_config)){
            return;
        }
        $return = [];
        foreach ($array as $key=>$value){
            if(in_array($key, $this->index_config)){
                if(!empty($value) && ($key == 'deleted_at' || $key == 'updated_at' || $key == 'created_at')){
                    $value = strtotime($value);
                }
                if(empty($value) && ($key == 'deleted_at' || $key == 'updated_at' || $key == 'created_at')){
                    $value = 0;
                }
                $return[$key] = $value;
            }
        }
        return $return;
    }
    
    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $models->first()->searchableAs();
        $this->values = [];
        $values = $models->map(function ($model) {
            $key = $model->getScoutKey();
            $this->values[$key] = [time()];
            return;
        });
        
        if(!empty($values)){
            $this->sphinx_client->UpdateAttributes($index, ['deleted_at'], $this->values);
        }
    }
    
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $builder->limit,
            'page' => 0,
        ]));
    }
    
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }
    
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $index = $builder->model->searchableAs();
        
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $index,
                $builder->query,
                $options
                );
        }
        $this->Filters($builder);
        
        $offset = intval($options['page'] * $options['hitsPerPage']);
        $limit = intval($options['hitsPerPage']);
        $this->sphinx_client->SetLimits($offset, $limit);
        
        return $this->sphinx_client->Query($builder->query, $index);
    }
    
    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $this->sphinx_client->SetFilter('deleted_at', [0]);
        if($builder->wheres){
            foreach ($builder->wheres as $key=>$value){
                $this->sphinx_client->SetFilter($key, [$value]);
            }
        }
    }
    
    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['matches'])->pluck('attrs')->pluck('new_id')->values();
    }
    
    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (intval($results['total']) === 0) {
            return $model->newCollection();
        }
        
        $ids = collect($results['matches'])->pluck('attrs')->pluck('new_id')->values()->all();
        
        return $model->getScoutModelsByIds(
            $builder, $ids
            )->filter(function ($model) use ($ids) {
                return in_array($model->getScoutKey(), $ids);
            });
    }
    
    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }
    
    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $this->index = $model->searchableAs();
        $model->chunk(500, function ($flights) {
            $values = [];
            foreach ($flights as $flight) {
                $values[$flight->id] = [time()];
            }
            $attrs = ['deleted_at'];
            $this->sphinx_client->UpdateAttributes($this->index, $attrs, $values);
        });
    }
    
    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}