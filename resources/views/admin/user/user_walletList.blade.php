@extends('admin._layoutNew')

@section('page-head')

@endsection

@section('page-content')
    <form class="layui-form" action="">
        @forelse($data as $item)
            <div class="layui-form-item">
                <label class="layui-form-label">
                    {{$item->name}}
                    @if ($item->type)
                        -{{$item->type}}
                    @endif
                </label>
                <div class="layui-input-block">
                    <input type="text" name="address[{{$loop->index}}]" autocomplete="off" class="layui-input" value="{{$item->address ?? ''}}"/>
                    <input type="hidden" name="ids[{{$loop->index}}]" autocomplete="off" class="layui-input" value="{{$item->id ?? ''}}"/>
                </div>
            </div>
        @empty
        @endforelse
        
        <div class="layui-form-item">
            <div class="layui-input-block">
                <button class="layui-btn" lay-submit lay-filter="form">立即提交</button>
                <button type="reset" class="layui-btn layui-btn-primary">重置</button>
            </div>
        </div>
    </form>
@endsection

@section('scripts')
<script>
    layui.use(['element', 'form', 'layer'], function () {
        var element = layui.element
            ,form = layui.form
            ,layer = layui.layer
            ,$ = layui.$
        form.on('submit(form)', function (data) {
            $.ajax({
                url: ''
                ,type: 'POST'
                ,data: data.field
                ,success: function (res) {
                    layer.msg(res.message, {
                        time: 2000
                        ,end: function () {
                            if (res.type == 'ok') {
                                var index = parent.layer.getFrameIndex(window.name); //先得到当前iframe层的索引
                                parent.layer.close(index); //再执行关闭 
                                parent.layui.table.reload('userlist');       
                            }
                        }
                    });
                }
                ,error: function (res) {
                    layer.msg('网络错误');
                }
            });
            return false;
        });
    });
</script>
@endsection