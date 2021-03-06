<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use App\Project\Eloquent\Board;
use App\Project\Eloquent\BoardRankMap;
use App\Project\Eloquent\AccessBoardLog;
use App\Project\Provider;

class BoardController extends Controller
{
    /**
     * get user accessed board list
     *
     * @param  string  $project_key
     * @return response 
     */
    public function index($project_key) {
        // get all boards
        $boards = Board::Where('project_key', $project_key)
            ->orderBy('_id', 'asc')
            ->get();

        $access_records = AccessBoardLog::where('project_key', $project_key)
            ->where('user_id', $this->user->id)
            ->orderBy('latest_access_time', 'desc')
            ->get();

        $list = [];
        $accessed_boards = [];
        foreach($access_records as $record)
        {
            foreach($boards as $board)
            {
                if ($board->id == $record->board_id)
                {
                    $accessed_boards[] = $record->board_id; 
                    break;
                }
            }
            if (in_array($record->board_id, $accessed_boards))
            {
                $list[] = $board;
            }
        }

        foreach ($boards as $board)
        {
            if (!in_array($board->id, $accessed_boards))
            {
                $list[] = $board;
            }
        }

        return Response()->json([ 'ecode' => 0, 'data' => $list ]);

/*
$example = [ 
  'id' => '111',
  'name' => '1111111111',
  'type' => 'kanban',
  'query' => [ 'type' => [ '59af4ad51d41c85e9108a8a7' ], 'subtask' => true ],
  'last_access_time' => 11111111,
  'columns' => [
    [ 'no' => 1, 'name' => '待处理', 'states' => [ 'Open', 'Reopened' ] ],
    [ 'no' => 2, 'name' => '处理中', 'states' => [ 'In Progess' ] ],
    [ 'no' => 3, 'name' => '关闭', 'states' => [ 'Resolved', 'Closed' ] ]
  ],
  'filters' => [
    [ 'no' => 1, 'id' => '11111', 'name' => '111111', 'query' => [ 'updated_at' => '1m' ] ],
    [ 'no' => 2, 'id' => '22222', 'name' => '222222' ],
    [ 'no' => 3, 'id' => '33333', 'name' => '333333' ],
  ],
];
        return Response()->json([ 'ecode' => 0, 'data' => [ $example ] ]);
*/
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $project_key)
    {
        $name = $request->input('name');
        if (!$name || trim($name) == '')
        {
            throw new \UnexpectedValueException('the name can not be empty.', -11600);
        }

        // only support for kanban type, fix me
        $board = Board::create([ 'project_key' => $project_key, 'type' => 'kanban', 'query' => [ 'subtask' => true ] ] + $request->all());
        return Response()->json(['ecode' => 0, 'data' => $board]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $project_key, $id)
    {
        $board = Board::find($id);
        if (!$board || $project_key != $board->project_key)
        {
            throw new \UnexpectedValueException('the board does not exist or is not in the project.', -11601);
        }

        $updValues = [];
        $name = $request->input('name');
        if (isset($name))
        {
            if (!$name || trim($name) == '')
            {
                throw new \UnexpectedValueException('the name can not be empty.', -11600);
            }
            $updValues['name'] = $name;
        }

        $description = $request->input('description');
        if (isset($description))
        {
            $updValues['description'] = $description;
        }

        $query = $request->input('query');
        if (isset($query))
        {
            // defaultly display subtask issue
            $updValues['query'] = [ 'subtask' => true ] + $query;
        }

        $filters = $request->input('filters');
        if (isset($filters))
        {
            $updValues['filters'] = $filters;
        }

        $columns = $request->input('columns');
        if (isset($columns))
        {
            $updValues['columns'] = $columns;
        }

        //$subtask = $request->input('subtask');
        //if (isset($subtask))
        //{
        //    $updValues['subtask'] = $subtask;
        //}

        $board->fill($updValues)->save();
        return Response()->json(['ecode' => 0, 'data' => Board::find($id)]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($project_key, $id)
    {
        $board = Board::find($id);
        //if (!$board || $project_key != $board->project_key)
        //{
        //    throw new \UnexpectedValueException('the board does not exist or is not in the project.', -10002);
        //}
        return Response()->json(['ecode' => 0, 'data' => $state]);
    }

    /**
     * get the kanban rank
     *
     * @param  string  $project_key
     * @param  string  $id
     * @return void
     */
    public function getRank($project_key, $id)
    {
        // record the access time
        $this->setAccess($project_key, $id);

        // get the kanban ranks
        $ranks = BoardRankMap::where([ 'board_id' => $id ])->get(); 
        return Response()->json(['ecode' => 0, 'data' => $ranks ]);
    }

    /**
     * rank the column issues 
     *
     * @param  string  $project_key
     * @param  string  $id
     * @return void
     */
    public function setRank(Request $request, $project_key, $id)
    {
        $col_no = $request->input('col_no');
        if (!isset($col_no))
        {
            throw new \UnexpectedValueException('the column no can not be empty.', -11602);
        }

        $parent = $request->input('parent');
        if (!$parent || trim($parent) == '')
        {
            $parent = '';
        }

        $rank = $request->input('rank');
        if (!isset($rank) || !$rank) 
        {
            throw new \UnexpectedValueException('the rank can not be empty.', -11603);
        }

        $old_rank = BoardRankMap::where([ 'board_id' => $id, 'col_no' => $col_no, 'parent' => $parent ])->first(); 
        $old_rank && $old_rank->delete();

        BoardRankMap::create([ 'board_id' => $id, 'col_no' => $col_no, 'parent' => $parent, 'rank' => $rank ]);

        $ranks = BoardRankMap::where([ 'board_id' => $id ])->get(); 
        return Response()->json(['ecode' => 0, 'data' => $ranks ]);
    }

    /**
     * record user access board
     *
     * @param  string  $project_key
     * @param  string  $id
     * @return void
     */
    public function setAccess($project_key, $id) 
    {
        $record = AccessBoardLog::where([ 'board_id' => $id, 'user_id' => $this->user->id ])->first();
        $record && $record->delete();

        AccessBoardLog::create([ 
          'project_key' => $project_key, 
          'user_id' => $this->user->id, 
          'board_id' => $id, 
          'latest_access_time' => time() ]);
        return Response()->json(['ecode' => 0, 'data' => [ 'id' => $id ] ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($project_key, $id)
    {
        $board = Board::find($id);
        if (!$board || $project_key != $board->project_key)
        {
            throw new \UnexpectedValueException('the board does not exist or is not in the project.', -11601);
        }

        // delete access log
        AccessBoardLog::where('board_id', $id)->delete();

        // delete board rank
        BoardRankMap::where('board_id', $id)->delete();

        Board::destroy($id);
        return Response()->json(['ecode' => 0, 'data' => ['id' => $id]]);
        
    }
}
