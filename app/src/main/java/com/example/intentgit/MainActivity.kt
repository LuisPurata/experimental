package com.example.intentgit

import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle

class MainActivity : AppCompatActivity() {

    var variable = "Hello World"
    var asdfs = "Como Estas Mundo Cruel"
    var v1 = 1
    var v2 = 2
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        var resultado = v1 + v2

    }
}